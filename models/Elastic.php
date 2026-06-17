<?php

namespace Rhymix\Modules\Es_search\Models;

use BaseObject;
use DocumentModel;
use PageHandler;
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
	 * @param object $obj
	 * @return BaseObject
	 */
	public static function search(object $obj): BaseObject
	{
		$page = max(1, (int)($obj->page ?? 1));
		$list_count = max(1, (int)($obj->list_count ?? 20));
		$page_count = max(1, (int)($obj->page_count ?? 10));

		$must = [[
			'multi_match' => [
				'query' => $obj->search_keyword,
				'fields' => ['title^3', 'content'],
			],
		]];
		$filter = [];

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
