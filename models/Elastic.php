<?php

namespace Rhymix\Modules\Es_search\Models;

use BaseObject;
use CommentModel;
use Context;
use DocumentModel;
use ModuleModel;
use PageHandler;
use Rhymix\Framework\Session;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

/**
 * ElasticSearch 클라이언트 래퍼.
 *
 * 색인 생성/삭제와 검색을 담당한다. 연결 실패 시 호출자가 잡아서
 * 기존 DB 검색으로 자동 전환(폴백)할 수 있도록 예외를 그대로 던진다.
 */
class Elastic
{
	protected static ?Client $_client = null;
	protected static bool $_indexEnsured = false;
	protected static bool $_commentIndexEnsured = false;

	/**
	 * ElasticSearch 클라이언트를 가져온다.
	 *
	 * @return Client
	 */
	public static function getClient(): Client
	{
		if (self::$_client === null)
		{
			$config = Config::getConfig();
			$scheme = $config->es_scheme;
			$host = $config->es_host;
			$port = $config->es_port;

			$builder = ClientBuilder::create()->setHosts([sprintf('%s://%s:%s', $scheme, $host, $port)]);
			if (!empty($config->es_username))
			{
				$builder->setBasicAuthentication($config->es_username, $config->es_password ?? '');
			}
			// 대량 재색인 시 Bulk 요청이 커서 기본 타임아웃(보통 매우 짧음)으로는 응답을
			// 받다가 끊겨 일부 항목 결과가 누락되는 현상이 있었다. 여유 있게 늘려준다.
			$builder->setHttpClientOptions(['timeout' => 60, 'connect_timeout' => 10]);
			self::$_client = $builder->build();
		}
		return self::$_client;
	}

	/**
	 * 색인 이름을 가져온다.
	 *
	 * @return string
	 */
	public static function getIndexName(): string
	{
		return Config::getConfig()->es_index;
	}

	/**
	 * 댓글 색인 이름을 가져온다. 문서 색인과는 매핑 구조가 달라 별도 인덱스로 관리하며,
	 * 이름도 (자동으로 접미사를 붙이지 않고) 설정에서 독립적으로 지정한다.
	 *
	 * @return string
	 */
	public static function getCommentIndexName(): string
	{
		return Config::getConfig()->es_comment_index;
	}

	/**
	 * 인덱스가 없으면 한국어(nori) 분석기를 사용하는 매핑으로 새로 만든다.
	 *
	 * ES 기본(standard) 분석기는 한국어 형태소 분석을 하지 않아 "흔적기관", "흔적을"처럼
	 * 조사/복합어가 붙은 토큰을 "흔적"이라는 검색어와 매칭하지 못한다 (DB의 LIKE 검색은
	 * 부분 일치라 다 잡아내는데 ES만 놓치는 현상의 원인). nori_tokenizer를
	 * decompound_mode=mixed로 쓰면 복합어 자체와 분해된 형태소를 모두 색인해서
	 * "흔적기관"에서도 "흔적"이 검색되게 한다.
	 *
	 * 매핑은 필드 생성 시점에 고정되므로, 기존에 standard 분석기로 만들어진 인덱스가
	 * 있다면 먼저 비우고(flushIndex) 다시 색인해야 이 매핑이 적용된다.
	 *
	 * @return void
	 */
	public static function ensureIndex(): void
	{
		// 인덱스 존재 확인은 HTTP 요청 한 번이 들기 때문에, 문서 하나마다 매번 확인하면
		// 대량 색인 시 속도가 크게 느려진다. 같은 PHP 프로세스(요청/CLI 실행) 안에서는
		// 한 번만 확인하면 충분하므로 결과를 캐시한다.
		if (self::$_indexEnsured)
		{
			return;
		}

		$index = self::getIndexName();
		try
		{
			if (self::getClient()->indices()->exists(['index' => $index])->asBool())
			{
				self::$_indexEnsured = true;
				return;
			}
		}
		catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e)
		{
			// 404 등은 무시하고 아래에서 생성한다.
		}

		self::getClient()->indices()->create([
			'index' => $index,
			'body' => [
				'settings' => [
					'analysis' => [
						'tokenizer' => [
							'es_search_nori_tokenizer' => [
								'type' => 'nori_tokenizer',
								'decompound_mode' => 'mixed',
							],
						],
						'analyzer' => [
							'es_search_nori_analyzer' => [
								'type' => 'custom',
								'tokenizer' => 'es_search_nori_tokenizer',
								'filter' => ['nori_part_of_speech', 'lowercase'],
							],
						],
					],
				],
				'mappings' => [
					'properties' => [
						'document_srl' => ['type' => 'long'],
						'module_srl' => ['type' => 'long'],
						'category_srl' => ['type' => 'long'],
						'member_srl' => ['type' => 'long'],
						'user_id' => ['type' => 'keyword'],
						'nick_name' => ['type' => 'keyword'],
						'title' => ['type' => 'text', 'analyzer' => 'es_search_nori_analyzer'],
						'content' => ['type' => 'text', 'analyzer' => 'es_search_nori_analyzer'],
						'status' => ['type' => 'keyword'],
						'regdate' => ['type' => 'keyword'],
						'list_order' => ['type' => 'long'],
						'update_order' => ['type' => 'long'],
						'readed_count' => ['type' => 'long'],
						'voted_count' => ['type' => 'long'],
						'comment_count' => ['type' => 'long'],
					],
				],
			],
		]);
		self::$_indexEnsured = true;
	}

	/**
	 * 댓글 인덱스가 없으면 문서 인덱스와 동일한 nori 분석기 매핑으로 새로 만든다.
	 * (분석기는 인덱스마다 별도로 설정해야 하므로 문서 인덱스 생성 시 정의한 설정을 그대로 복사한다.)
	 *
	 * @return void
	 */
	public static function ensureCommentIndex(): void
	{
		if (self::$_commentIndexEnsured)
		{
			return;
		}

		$index = self::getCommentIndexName();
		try
		{
			if (self::getClient()->indices()->exists(['index' => $index])->asBool())
			{
				self::$_commentIndexEnsured = true;
				return;
			}
		}
		catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e)
		{
			// 404 등은 무시하고 아래에서 생성한다.
		}

		self::getClient()->indices()->create([
			'index' => $index,
			'body' => [
				'settings' => [
					'analysis' => [
						'tokenizer' => [
							'es_search_nori_tokenizer' => [
								'type' => 'nori_tokenizer',
								'decompound_mode' => 'mixed',
							],
						],
						'analyzer' => [
							'es_search_nori_analyzer' => [
								'type' => 'custom',
								'tokenizer' => 'es_search_nori_tokenizer',
								'filter' => ['nori_part_of_speech', 'lowercase'],
							],
						],
					],
				],
				'mappings' => [
					'properties' => [
						'comment_srl' => ['type' => 'long'],
						'document_srl' => ['type' => 'long'],
						'module_srl' => ['type' => 'long'],
						// 댓글 테이블에는 category_srl이 없어 글(문서)에서 가져와 함께 색인한다.
						// (게시판 검색에서 카테고리로 댓글 검색 결과를 좁힐 수 있도록)
						'category_srl' => ['type' => 'long'],
						'member_srl' => ['type' => 'long'],
						'user_id' => ['type' => 'keyword'],
						'nick_name' => ['type' => 'keyword'],
						'content' => ['type' => 'text', 'analyzer' => 'es_search_nori_analyzer'],
						'is_secret' => ['type' => 'keyword'],
						'status' => ['type' => 'long'],
						'regdate' => ['type' => 'keyword'],
						'list_order' => ['type' => 'long'],
					],
				],
			],
		]);
		self::$_commentIndexEnsured = true;
	}

	/**
	 * DocumentItem을 ES 색인용 body 배열로 변환한다.
	 *
	 * @param object $oDocument DocumentItem
	 * @return array
	 */
	protected static function buildDocumentBody($oDocument): array
	{
		return [
			'document_srl' => (int)$oDocument->document_srl,
			'module_srl' => (int)$oDocument->get('module_srl'),
			'category_srl' => (int)$oDocument->get('category_srl'),
			'member_srl' => (int)$oDocument->get('member_srl'),
			'user_id' => (string)$oDocument->get('user_id'),
			'nick_name' => (string)$oDocument->get('nick_name'),
			'title' => $oDocument->get('title'),
			'content' => $oDocument->getContentText(),
			'status' => $oDocument->get('status'),
			'regdate' => (string)$oDocument->get('regdate'),
			'list_order' => (int)$oDocument->get('list_order'),
			'update_order' => (int)$oDocument->get('update_order'),
			'readed_count' => (int)$oDocument->get('readed_count'),
			'voted_count' => (int)$oDocument->get('voted_count'),
			'comment_count' => (int)$oDocument->get('comment_count'),
		];
	}

	/**
	 * 문서를 색인한다.
	 *
	 * @param object $oDocument DocumentItem
	 * @return void
	 */
	public static function indexDocument($oDocument): void
	{
		self::ensureIndex();
		self::getClient()->index([
			'index' => self::getIndexName(),
			'id' => (string)$oDocument->document_srl,
			'body' => self::buildDocumentBody($oDocument),
		]);
	}

	/**
	 * 문서 색인을 삭제한다.
	 *
	 * @param int $document_srl
	 * @return void
	 */
	public static function deleteDocument(int $document_srl): void
	{
		try
		{
			self::getClient()->delete([
				'index' => self::getIndexName(),
				'id' => (string)$document_srl,
			]);
		}
		catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e)
		{
			// 이미 없는 문서는 무시한다 (404).
		}
	}

	/**
	 * 여러 문서의 색인/삭제를 ES Bulk API로 한 번에 처리한다.
	 * 재색인/동기화 배치 처리에서 문서당 HTTP 요청을 보내는 대신 사용해 속도를 크게 높인다.
	 *
	 * @param array $documents 색인할 DocumentItem 배열
	 * @param array $delete_srls 삭제할 document_srl 배열
	 * @return array document_srl => null(성공) | string(에러 메시지)
	 */
	public static function bulkSync(array $documents, array $delete_srls): array
	{
		if (!$documents && !$delete_srls)
		{
			return [];
		}

		self::ensureIndex();
		$index = self::getIndexName();

		$body = [];
		$order = [];
		foreach ($documents as $oDocument)
		{
			$srl = (int)$oDocument->document_srl;
			$body[] = ['index' => ['_index' => $index, '_id' => (string)$srl]];
			$body[] = self::buildDocumentBody($oDocument);
			$order[] = $srl;
		}
		foreach ($delete_srls as $srl)
		{
			$srl = (int)$srl;
			$body[] = ['delete' => ['_index' => $index, '_id' => (string)$srl]];
			$order[] = $srl;
		}

		$response = self::getClient()->bulk(['body' => $body])->asArray();

		$results = [];
		foreach ($response['items'] ?? [] as $i => $item)
		{
			$action = array_key_first($item);
			$status = (int)($item[$action]['status'] ?? 500);
			// delete는 대상이 이미 없어서 404가 나도 결과적으로는 성공(이미 삭제된 상태)으로 본다.
			$ok = ($status >= 200 && $status < 300) || ($action === 'delete' && $status === 404);
			$results[$order[$i]] = $ok ? null : ($item[$action]['error']['reason'] ?? ('HTTP ' . $status));
		}
		return $results;
	}

	/**
	 * CommentItem을 ES 색인용 body 배열로 변환한다.
	 *
	 * @param object $oComment CommentItem
	 * @param array $document_info 댓글이 달린 글 정보. ['category_srl' => int, 'secret' => bool]
	 *        (댓글 테이블에는 category_srl이 없어 함께 받아오고, 글이 비밀글이면 댓글 자신의
	 *        is_secret 값과 무관하게 비밀댓글로 취급한다)
	 * @return array
	 */
	protected static function buildCommentBody($oComment, array $document_info = []): array
	{
		$is_secret = ($oComment->get('is_secret') === 'Y' || !empty($document_info['secret'])) ? 'Y' : 'N';
		return [
			'comment_srl' => (int)$oComment->comment_srl,
			'document_srl' => (int)$oComment->get('document_srl'),
			'module_srl' => (int)$oComment->get('module_srl'),
			'category_srl' => (int)($document_info['category_srl'] ?? 0),
			'member_srl' => (int)$oComment->get('member_srl'),
			'user_id' => (string)$oComment->get('user_id'),
			'nick_name' => (string)$oComment->get('nick_name'),
			'content' => $oComment->getContentText(),
			'is_secret' => $is_secret,
			'status' => (int)$oComment->get('status'),
			'regdate' => (string)$oComment->get('regdate'),
			'list_order' => (int)$oComment->get('list_order'),
		];
	}

	/**
	 * 여러 댓글의 색인/삭제를 ES Bulk API로 한 번에 처리한다. bulkSync()의 댓글 버전.
	 *
	 * @param array $comments 색인할 CommentItem 배열
	 * @param array $document_info_map document_srl => ['category_srl' => int, 'secret' => bool] (buildCommentBody용)
	 * @param array $delete_srls 삭제할 comment_srl 배열
	 * @return array comment_srl => null(성공) | string(에러 메시지)
	 */
	public static function bulkSyncComments(array $comments, array $document_info_map, array $delete_srls): array
	{
		if (!$comments && !$delete_srls)
		{
			return [];
		}

		self::ensureCommentIndex();
		$index = self::getCommentIndexName();

		$body = [];
		$order = [];
		foreach ($comments as $oComment)
		{
			$srl = (int)$oComment->comment_srl;
			$document_srl = (int)$oComment->get('document_srl');
			$body[] = ['index' => ['_index' => $index, '_id' => (string)$srl]];
			$body[] = self::buildCommentBody($oComment, $document_info_map[$document_srl] ?? []);
			$order[] = $srl;
		}
		foreach ($delete_srls as $srl)
		{
			$srl = (int)$srl;
			$body[] = ['delete' => ['_index' => $index, '_id' => (string)$srl]];
			$order[] = $srl;
		}

		$response = self::getClient()->bulk(['body' => $body])->asArray();

		$results = [];
		foreach ($response['items'] ?? [] as $i => $item)
		{
			$action = array_key_first($item);
			$status = (int)($item[$action]['status'] ?? 500);
			$ok = ($status >= 200 && $status < 300) || ($action === 'delete' && $status === 404);
			$results[$order[$i]] = $ok ? null : ($item[$action]['error']['reason'] ?? ('HTTP ' . $status));
		}
		return $results;
	}

	/**
	 * 댓글 색인을 비운다 (인덱스를 삭제하고, nori 분석기 매핑으로 바로 다시 만든다).
	 *
	 * @return void
	 */
	public static function flushCommentIndex(): void
	{
		try
		{
			self::getClient()->indices()->delete([
				'index' => self::getCommentIndexName(),
			]);
		}
		catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e)
		{
			// 인덱스가 이미 없는 경우 무시한다 (404).
		}
		self::$_commentIndexEnsured = false;
		self::ensureCommentIndex();
	}

	/**
	 * 색인 전체를 비운다 (인덱스를 삭제하고, nori 분석기 매핑으로 바로 다시 만든다).
	 *
	 * @return void
	 */
	public static function flushIndex(): void
	{
		try
		{
			self::getClient()->indices()->delete([
				'index' => self::getIndexName(),
			]);
		}
		catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e)
		{
			// 인덱스가 이미 없는 경우 무시한다 (404).
		}
		self::$_indexEnsured = false;
		self::ensureIndex();
	}

	/**
	 * 클러스터 노드에 nori(한국어 형태소 분석) 플러그인이 설치되어 있는지 확인한다.
	 *
	 * 미설치 상태에서는 ensureIndex()가 만드는 es_search_nori_analyzer 매핑 자체가
	 * 실패하거나(인덱스가 standard 분석기로 만들어졌거나) 색인이 깨지기 쉬우므로,
	 * 관리자 화면에서 미리 알려줘 운영자가 플러그인을 설치하도록 유도한다.
	 *
	 * ES에 연결조차 할 수 없는 경우에는 nori 설치 여부와 무관한 문제이므로 null을 반환해
	 * 호출부가 "미설치" 경고와 "연결 실패" 상황을 구분할 수 있게 한다.
	 *
	 * @return bool|null true: 설치됨, false: 미설치 확인됨, null: 확인 불가(연결 실패 등)
	 */
	public static function isNoriInstalled(): ?bool
	{
		try
		{
			$response = self::getClient()->nodes()->info(['metric' => 'plugins']);
			$nodes = $response->asArray()['nodes'] ?? [];
			foreach ($nodes as $node)
			{
				foreach ($node['plugins'] ?? [] as $plugin)
				{
					if (($plugin['name'] ?? '') === 'analysis-nori')
					{
						return true;
					}
				}
			}
			return false;
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	/**
	 * 빈 검색 결과를 document.getDocumentList와 동일한 형태로 만들어 반환한다.
	 *
	 * board.view.php 등 호출부는 $output->toBool() 여부를 확인하지 않고
	 * data/total_count/page/page_navigation을 바로 사용하므로, 에러 객체(BaseObject(-1, ...))를
	 * 반환하면 page_navigation이 없어 스킨에서 치명적 오류가 발생한다. 따라서 검색을 막아야 할
	 * 때도 항상 "성공한 빈 결과" 형태로 반환해야 한다.
	 *
	 * @param object $obj
	 * @param string $message
	 * @return BaseObject
	 */
	public static function emptyResult(object $obj, string $message = ''): BaseObject
	{
		$output = self::buildListOutput([], 0, $obj);
		if ($message !== '')
		{
			$output->message = $message;
		}
		return $output;
	}

	/**
	 * ElasticSearch로 게시판 검색을 수행하고, document.getDocumentList와
	 * 동일한 형태의 BaseObject를 만들어 반환한다.
	 *
	 * 상담 게시판 글도 다른 글과 동일하게 여기서 검색된다 - "관리자가 아니면 자기 글만"
	 * 제약은 이 메소드가 아니라 호출부(board.view.php 등)가 $obj->member_srl을 적절히
	 * 좁혀서 넘겨주는 것에 의존한다. 자세한 내용과 새 호출부 추가 시 주의사항은
	 * EventHandlers 클래스 docblock의 "상담 게시판 노출 범위 전제" 설명을 참고할 것.
	 *
	 * @param object $obj
	 * @return BaseObject
	 */
	public static function search(object $obj): BaseObject
	{
		if ((string)($obj->search_target ?? '') === 'comment')
		{
			return self::searchComment($obj);
		}

		$page = max(1, (int)($obj->page ?? 1));
		$list_count = max(1, (int)($obj->list_count ?? 20));
		$page_count = max(1, (int)($obj->page_count ?? 10));

		$must = [self::buildSearchQuery($obj)];
		$filter = [];

		// 작성글 보기(dispMemberOwnDocument) 등 호출부가 글쓴이 본인 글로 결과를 한정하기 위해
		// 넘기는 필터다. 이 필터가 없으면(과거 버그) 검색이 전체 글을 대상으로 동작해버린다.
		if (!empty($obj->member_srl))
		{
			$filter[] = ['terms' => ['member_srl' => array_map('intval', (array)$obj->member_srl)]];
		}

		if (!empty($obj->module_srl))
		{
			$filter[] = ['terms' => ['module_srl' => array_map('intval', (array)$obj->module_srl)]];
		}
		if (!empty($obj->exclude_module_srl))
		{
			$filter[] = ['bool' => ['must_not' => [
				['terms' => ['module_srl' => array_map('intval', (array)$obj->exclude_module_srl)]],
			]]];
		}
		if (!empty($obj->category_srl))
		{
			$filter[] = ['terms' => ['category_srl' => array_map('intval', (array)$obj->category_srl)]];
		}

		$response = self::getClient()->search([
			'index' => self::getIndexName(),
			'body' => [
				'query' => ['bool' => ['must' => $must, 'filter' => $filter]],
				'from' => ($page - 1) * $list_count,
				'size' => $list_count,
				'_source' => ['document_srl'],
				'sort' => self::resolveSort($obj),
			],
		])->asArray();

		$hits = $response['hits']['hits'] ?? [];
		$total_count = (int)($response['hits']['total']['value'] ?? 0);

		$srls = array_map(fn($hit) => (int)$hit['_source']['document_srl'], $hits);
		$documents_map = $srls ? DocumentModel::getDocuments($srls) : [];

		$data = [];
		foreach ($srls as $srl)
		{
			if (isset($documents_map[$srl]))
			{
				$data[] = $documents_map[$srl];
			}
		}

		return self::buildListOutput($data, $total_count, $obj);
	}

	/**
	 * 검색 요청이 비밀댓글까지 볼 수 있는 권한인지 확인한다 (searchComment()의 비밀댓글
	 * 제외 여부 판단용). document.model.php의 _setSearchOption()이 'comment' 검색
	 * 대상에서 comment_is_secret을 정하는 로직과 동일한 기준을 쓴다:
	 *  - 검색 요청이 이미 본인 글/댓글로만 한정되어 있다면(member_srl이 본인) 항상 허용.
	 *  - 그렇지 않다면, 검색 대상 게시판(module_srl) 전체에 대해 매니저 권한이 있어야 허용.
	 *    (module_srl이 없으면 권한 없음으로 취급 - 안전한 기본값)
	 *
	 * @param object $obj
	 * @return bool
	 */
	protected static function canViewSecretComments(object $obj): bool
	{
		if (!empty($obj->member_srl))
		{
			$my_srl = Session::getMemberSrl();
			$member_srls = array_map('intval', (array)$obj->member_srl);
			if ($my_srl && !array_diff($member_srls, [$my_srl, -$my_srl]))
			{
				return true;
			}
		}

		$logged_info = Context::get('logged_info');
		$module_srls = (array)($obj->module_srl ?? []);
		if (!$module_srls)
		{
			$module_srls = [null];
		}
		foreach ($module_srls as $module_srl)
		{
			$module_info = $module_srl ? ModuleModel::getModuleInfoByModuleSrl((int)$module_srl) : null;
			if (!ModuleModel::getGrant($module_info, $logged_info)->manager)
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * search_target=comment 검색을 수행한다 (document.getDocumentListWithinComment에 대응).
	 *
	 * 댓글 내용으로 검색하지만, 목록에는 댓글이 아니라 "댓글이 달린 글"을 중복 없이 표시해야
	 * 한다 (한 글에 매칭되는 댓글이 여러 개여도 글은 한 번만 나온다). ES의 collapse 기능으로
	 * document_srl 기준 중복을 제거하고, 전체 글 수는 cardinality 집계로 따로 구한다
	 * (collapse는 응답에 원본 hit 수를 그대로 주기 때문에 total을 그대로 쓰면 댓글 수가 되어버린다).
	 *
	 * 상담 게시판 댓글의 노출 범위 제어는 호출부 의존이다 - EventHandlers 클래스 docblock의
	 * "상담 게시판 노출 범위 전제" 참고.
	 *
	 * @param object $obj
	 * @return BaseObject
	 */
	protected static function searchComment(object $obj): BaseObject
	{
		$page = max(1, (int)($obj->page ?? 1));
		$list_count = max(1, (int)($obj->list_count ?? 20));

		$keyword = (string)$obj->search_keyword;
		$must = [[
			'multi_match' => [
				'query' => $keyword,
				'fields' => ['content'],
				'type' => 'phrase',
			],
		]];

		// 색인에는 발행 완료(RX_STATUS_PUBLIC) 댓글만 들어가지만, 방어적으로 한 번 더 확인한다.
		$filter = [['term' => ['status' => \RX_STATUS_PUBLIC]]];

		if (!empty($obj->module_srl))
		{
			$filter[] = ['terms' => ['module_srl' => array_map('intval', (array)$obj->module_srl)]];
		}
		if (!empty($obj->exclude_module_srl))
		{
			$filter[] = ['bool' => ['must_not' => [
				['terms' => ['module_srl' => array_map('intval', (array)$obj->exclude_module_srl)]],
			]]];
		}
		if (!empty($obj->category_srl))
		{
			$filter[] = ['terms' => ['category_srl' => array_map('intval', (array)$obj->category_srl)]];
		}
		// 비밀 댓글은, 검색 대상 게시판(들)의 매니저가 아니면 검색 결과에서 내용이 노출되지
		// 않도록 제외한다. document.model.php의 _setSearchOption()이 comment_is_secret을
		// 정하는 방식(검색 대상 게시판 전체에 대해 매니저 권한이 있어야 비밀글도 보임)과
		// 기준을 맞춘 것이다 - 최고관리자 여부만 보면 게시판 매니저(최고관리자 아님)가 자기
		// 게시판에서 비밀댓글을 못 찾는 차이가 생긴다.
		if (!self::canViewSecretComments($obj))
		{
			$filter[] = ['term' => ['is_secret' => 'N']];
		}

		$order_type = (($obj->order_type ?? 'asc') === 'desc') ? 'desc' : 'asc';

		$response = self::getClient()->search([
			'index' => self::getCommentIndexName(),
			'body' => [
				'query' => ['bool' => ['must' => $must, 'filter' => $filter]],
				'collapse' => ['field' => 'document_srl'],
				'sort' => [['list_order' => $order_type], ['comment_srl' => 'desc']],
				'from' => ($page - 1) * $list_count,
				'size' => $list_count,
				'_source' => ['document_srl'],
				'aggs' => ['doc_count' => ['cardinality' => ['field' => 'document_srl']]],
			],
		])->asArray();

		$hits = $response['hits']['hits'] ?? [];
		$total_count = (int)($response['aggregations']['doc_count']['value'] ?? 0);

		$srls = array_map(fn($hit) => (int)$hit['_source']['document_srl'], $hits);
		$documents_map = $srls ? DocumentModel::getDocuments($srls) : [];

		$data = [];
		foreach ($srls as $srl)
		{
			if (isset($documents_map[$srl]))
			{
				$data[] = $documents_map[$srl];
			}
		}

		// ES 정렬은 매칭된 댓글 기준(list_order)이라, 게시판이 요청한 글 기준 정렬(예: 조회수)과
		// 다를 수 있다. 페이지 안의 결과(최대 list_count건)만 다시 정렬해서 보여준다.
		self::sortDocumentsLikeRequested($data, $obj);

		return self::buildListOutput($data, $total_count, $obj);
	}

	/**
	 * 댓글 게시판 검색(comment) 결과를 게시판이 요청한 정렬 기준으로 다시 정렬한다.
	 * searchComment()는 ES 단계에서는 댓글 자체의 list_order로 정렬하므로, 최종 페이지
	 * 안에서는 게시판이 기대하는 글 기준 정렬(resolveSort()와 동일한 필드)로 맞춰준다.
	 *
	 * @param array $documents DocumentItem 배열 (참조로 직접 정렬한다)
	 * @param object $obj
	 * @return void
	 */
	protected static function sortDocumentsLikeRequested(array &$documents, object $obj): void
	{
		$sort_fields = ['list_order', 'update_order', 'regdate', 'readed_count', 'voted_count', 'comment_count'];
		$sort_index = (string)($obj->sort_index ?? 'list_order');
		$field = in_array($sort_index, $sort_fields) ? $sort_index : 'list_order';
		$order_type = (($obj->order_type ?? 'asc') === 'desc') ? -1 : 1;

		usort($documents, function ($a, $b) use ($field, $order_type) {
			return ($a->get($field) <=> $b->get($field)) * $order_type;
		});
	}

	/**
	 * comment.getTotalCommentList(예: dispMemberOwnComment, 내 댓글 검색)의 content 검색을
	 * ES로 대체한다. 이 목록은 글 단위 중복 제거 없이 "댓글" 하나하나를 그대로 보여준다.
	 *
	 * 반환하는 data는 CommentModel::getTotalCommentList()가 평소 DB에서 읽어오는 행과
	 * 동일한 형태(원본 comments 컬럼 + document_title 등 document_* 별칭)의 stdClass
	 * 배열이어야 한다. 호출부가 이 배열을 그대로 CommentItem으로 감싸서 쓰기 때문이다.
	 *
	 * 상담 게시판 댓글의 노출 범위 제어는 호출부 의존이다 - EventHandlers 클래스 docblock의
	 * "상담 게시판 노출 범위 전제" 참고.
	 *
	 * @param object $args comment.getTotalCommentList가 만든 쿼리 인자 (s_content, s_member_srl 등)
	 * @param string $keyword
	 * @return BaseObject
	 */
	public static function searchMemberComments(object $args, string $keyword): BaseObject
	{
		$page = max(1, (int)($args->page ?? 1));
		$list_count = max(1, (int)($args->list_count ?? 20));

		$must = [[
			'multi_match' => [
				'query' => $keyword,
				'fields' => ['content'],
				'type' => 'phrase',
			],
		]];

		$filter = [['term' => ['status' => \RX_STATUS_PUBLIC]]];
		if (!empty($args->s_member_srl))
		{
			$filter[] = ['terms' => ['member_srl' => array_map('intval', (array)$args->s_member_srl)]];
		}
		if (!empty($args->s_module_srl))
		{
			$filter[] = ['terms' => ['module_srl' => array_map('intval', (array)$args->s_module_srl)]];
		}
		if (!empty($args->exclude_module_srl))
		{
			$filter[] = ['bool' => ['must_not' => [
				['terms' => ['module_srl' => array_map('intval', (array)$args->exclude_module_srl)]],
			]]];
		}

		// comment.getTotalCommentList의 기본 정렬은 항상 comments.list_order 오름차순으로 고정되어 있다.
		$response = self::getClient()->search([
			'index' => self::getCommentIndexName(),
			'body' => [
				'query' => ['bool' => ['must' => $must, 'filter' => $filter]],
				'sort' => [['list_order' => 'asc'], ['comment_srl' => 'asc']],
				'from' => ($page - 1) * $list_count,
				'size' => $list_count,
				'_source' => ['comment_srl'],
			],
		])->asArray();

		$hits = $response['hits']['hits'] ?? [];
		$total_count = (int)($response['hits']['total']['value'] ?? 0);

		$comment_srls = array_map(fn($hit) => (int)$hit['_source']['comment_srl'], $hits);
		$comments_map = $comment_srls ? CommentModel::getComments($comment_srls) : [];

		$document_srls = [];
		foreach ($comments_map as $oComment)
		{
			$document_srls[(int)$oComment->get('document_srl')] = true;
		}
		$documents_map = $document_srls
			? DocumentModel::getDocuments(array_keys($document_srls), false, false, [
				'document_srl', 'module_srl', 'member_srl', 'user_id', 'user_name', 'nick_name', 'title',
			])
			: [];

		$data = [];
		foreach ($comment_srls as $srl)
		{
			if (!isset($comments_map[$srl]))
			{
				continue;
			}
			$oComment = $comments_map[$srl];
			$row = (object)$oComment->variables;

			$document_srl = (int)$oComment->get('document_srl');
			if (isset($documents_map[$document_srl]))
			{
				$oDocument = $documents_map[$document_srl];
				$row->module_srl = $oDocument->get('module_srl');
				$row->document_member_srl = $oDocument->get('member_srl');
				$row->document_user_id = $oDocument->get('user_id');
				$row->document_user_name = $oDocument->get('user_name');
				$row->document_nick_name = $oDocument->get('nick_name');
				$row->document_title = $oDocument->get('title');
			}
			$data[] = $row;
		}

		return self::buildListOutput($data, $total_count, $args);
	}

	/**
	 * search_target에 따라 ES 쿼리(must 절 하나)를 만든다.
	 *
	 * document.getDocumentList의 search_target별 동작(document.model.php의
	 * _setSearchOption)을 ES 쿼리로 옮긴 것이다:
	 * - title: 제목만 검색 (s_title만 설정됨)
	 * - content: 본문만 검색 (s_content만 설정됨)
	 * - title_content: 제목+본문 검색 (s_title, s_content 둘 다 OR로 설정됨)
	 * - member_srl: 글쓴이 회원번호로 정확히 일치하는 글 (s_member_srl = equal)
	 * - nick_name/user_id: 별명/사용자 ID 부분 일치 (s_nick_name/s_user_id = like_prefix,
	 *   공백은 중간 와일드카드로 취급됨 - str_replace(' ', '%', ...))
	 * - regdate: 등록일 앞부분 일치 (s_regdate = like_prefix, 숫자만 추출)
	 *
	 * @param object $obj
	 * @return array
	 */
	protected static function buildSearchQuery(object $obj): array
	{
		$search_target = (string)($obj->search_target ?? '');
		$keyword = (string)$obj->search_keyword;

		switch ($search_target)
		{
			case 'member_srl':
				return ['term' => ['member_srl' => (int)$keyword]];

			case 'nick_name':
			case 'user_id':
				$pattern = str_replace(' ', '*', $keyword) . '*';
				return ['wildcard' => [$search_target => ['value' => $pattern, 'case_insensitive' => true]]];

			case 'regdate':
				$digits = preg_replace('/[^\d]/', '', $keyword);
				return ['prefix' => ['regdate' => $digits]];

			case 'title':
				$fields = ['title'];
				break;

			case 'content':
				$fields = ['content'];
				break;

			default:
				// title_content 및 그 외 알 수 없는 값은 기존처럼 제목+본문을 모두 검색한다.
				$fields = ['title^3', 'content'];
				break;
		}

		// type을 지정하지 않으면 기본값(best_fields, OR)이 적용되어, nori가 "24일"을
		// "24"/"일"처럼 여러 토큰으로 쪼갰을 때 "일"처럼 흔한 토큰 하나만 들어간 무관한
		// 글까지 매칭되어버린다. phrase로 지정하면 토큰들이 원문과 같은 순서로 붙어 있는
		// 경우만 매칭되어, DB의 부분 일치(LIKE) 검색과 가까운 결과를 얻을 수 있다.
		return [
			'multi_match' => [
				'query' => $keyword,
				'fields' => $fields,
				'type' => 'phrase',
			],
		];
	}

	/**
	 * 게시판이 요청한 정렬 기준(sort_index/order_type)을 ES sort 절로 변환한다.
	 *
	 * list_order/update_order/조회수/추천수/댓글수는 모두 색인된 필드를 그대로 쓴다.
	 * "끌어올리기"처럼 document.updateDocument 트리거를 거치지 않고 DB를 직접 수정하는
	 * 기능이 있다면, 그 쪽에서 SearchLog::markPending()을 직접 호출해 동기화 큐에 올려야
	 * ES 색인이 최신 list_order를 반영한다 (예: modules/yeokbox/controllers/DocumentFunc.php).
	 *
	 * @param object $obj
	 * @return array
	 */
	protected static function resolveSort(object $obj): array
	{
		// regdate는 ensureIndex()에서 keyword로 명시적으로 매핑하므로 그대로 정렬 가능하다.
		$sort_fields = [
			'list_order' => 'list_order',
			'update_order' => 'update_order',
			'regdate' => 'regdate',
			'readed_count' => 'readed_count',
			'voted_count' => 'voted_count',
			'comment_count' => 'comment_count',
		];

		$sort_index = (string)($obj->sort_index ?? 'list_order');
		$field = $sort_fields[$sort_index] ?? $sort_fields['list_order'];
		$order_type = (($obj->order_type ?? 'asc') === 'desc') ? 'desc' : 'asc';

		// 동일 값 동률일 때 정렬 순서가 흔들리지 않도록 document_srl을 보조 정렬 키로 추가한다.
		return [[$field => $order_type], ['document_srl' => 'desc']];
	}

	/**
	 * document.getDocumentList가 반환하는 것과 동일한 형태의 BaseObject를 만든다.
	 *
	 * BaseObject::add()/set()은 내부 변수 버킷에만 저장되어 $output->data 같은
	 * 직접 프로퍼티 접근으로는 읽을 수 없다 (__get이 없음). 호출부(board.view.php 등)가
	 * 기대하는 형태와 동일하게 실제 public 프로퍼티로 직접 대입해야 한다.
	 * (참고: modules/supercache/supercache.model.php의 _fillPaginationData())
	 *
	 * @param array $data
	 * @param int $total_count
	 * @param object $obj page/list_count/page_count를 읽어온다
	 * @return BaseObject
	 */
	protected static function buildListOutput(array $data, int $total_count, object $obj): BaseObject
	{
		$page = max(1, (int)($obj->page ?? 1));
		$list_count = max(1, (int)($obj->list_count ?? 20));
		$page_count = max(1, (int)($obj->page_count ?? 10));
		$total_page = max(1, (int)ceil($total_count / $list_count));

		$output = new BaseObject;
		$output->data = $data;
		$output->total_count = $total_count;
		$output->total_page = $total_page;
		$output->page = $page;
		$output->page_navigation = new PageHandler($total_count, $total_page, $page, $page_count);
		return $output;
	}
}
