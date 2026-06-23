<?php

namespace Rhymix\Modules\Es_search\Controllers;

use CommentModel;
use Context;
use DocumentModel;
use ModuleModel;
use Rhymix\Framework\Session;
use Rhymix\Modules\Es_search\Models\Config as ConfigModel;
use Rhymix\Modules\Es_search\Models\Elastic as ElasticModel;
use Rhymix\Modules\Es_search\Models\SearchLog as SearchLogModel;

/**
 * 트리거(이벤트 핸들러)를 모아 놓은 클래스.
 *
 * module.xml의 <eventHandlers> 섹션에서 어떤 이벤트를 어느 메소드로 보낼지 정의한다.
 *
 * ============================================================================
 * 상담(consultation) 게시판 글/댓글의 노출 범위에 대한 중요한 전제 (필독)
 * ============================================================================
 * 상담 게시판 글/댓글은 일반 글/댓글과 동일하게 ES에 색인된다(별도 제외 없음). 상담 게시판의
 * "관리자가 아니면 자기 글만 보임" 제약은 ES가 아니라 **호출부가 검색 요청에 member_srl을
 * 제대로 좁혀서 넘겨준다는 가정**에 전적으로 의존한다. 실제로:
 *  - board.view.php의 dispBoardContentList()는 init() 단계에서 이미 $this->consultation
 *    여부를 판단해두고, 상담 게시판이면서 매니저가 아닐 때 $args->member_srl을 [내 회원번호,
 *    -내 회원번호]로 강제 설정한다 (검색 여부와 무관하게 항상).
 *  - member.view.php의 dispMemberOwnDocument()/dispMemberOwnComment()는 항상 자기 자신의
 *    member_srl로만 조회한다.
 * 즉, document.getDocumentList / comment.getTotalCommentList 트리거가 fire될 때 이미
 * member_srl이 적절히 좁혀져 있는 호출부에서만 안전하다.
 *
 * 따라서 이 모듈에 새로운 후킹 지점을 추가하거나(예: 다른 이벤트, 다른 모듈의 검색 기능),
 * ES 검색 결과를 노출하는 새로운 경로를 만들 때는 반드시 다음을 확인할 것:
 *   1. 그 호출부가 상담 게시판에 대해 board.view.php와 동등한 member_srl 제한을 이미
 *      걸어주는가? (예: integration_search처럼 여러 모듈을 가로지르며 member_srl 제한 없이
 *      검색하는 기능이라면, 상담 게시판의 글쓴이 제한이 보장되지 않는다.)
 *   2. 보장되지 않는다면, 호출부를 고치거나, 이 트리거 핸들러 또는 ElasticModel의 검색
 *      메소드 안에서 상담 게시판 module_srl을 별도로 제외/제한하는 로직을 추가해야 한다.
 *      (참고: 이 클래스의 getConsultationModuleSrls()/isConsultationModule()이 상담
 *      게시판 판별에 쓸 수 있는 기존 헬퍼다. 과거에는 ElasticModel::isOwnContentRequest()/
 *      buildConsultationExcludeFilter()로 이런 제외 로직을 구현했다가, 이 모듈이 후킹하는
 *      두 경로(게시판별 검색, 작성 글/댓글 보기)에서는 호출부가 이미 member_srl을 보장하므로
 *      중복이라 판단해 제거했다 - 새 후킹 지점을 추가한다면 그 판단이 그 경로에도 똑같이
 *      적용되는지 다시 확인해야 한다.)
 * ============================================================================
 */
class EventHandlers extends Base
{
	/**
	 * 검색 가능한 search_target 목록 (제목/본문/회원번호/별명/사용자 ID/등록일 검색을 ES로 대체한다).
	 */
	protected const SEARCHABLE_TARGETS = ['title', 'content', 'title_content', 'comment', 'member_srl', 'nick_name', 'user_id', 'regdate'];

	/**
	 * document.getDocumentList (before)
	 *
	 * 게시판 검색 요청이면 ElasticSearch 검색 결과로 대체한다.
	 * ES 연결 실패 시에는 기존 DB 검색으로 자동 전환된다.
	 *
	 * @param object $obj
	 * @return void
	 */
	public function beforeGetDocumentList($obj)
	{
		$search_target = $obj->search_target ?? null;
		$search_keyword = trim((string)($obj->search_keyword ?? ''));

		if (!$search_target || !$search_keyword || !in_array($search_target, self::SEARCHABLE_TARGETS))
		{
			return;
		}

		$config = ConfigModel::getConfig();
		if (empty($config->enabled))
		{
			return;
		}

		if (!empty($config->member_only_search) && !Session::getMemberSrl())
		{
			// board.view.php 등 호출부는 $output->toBool() 여부를 확인하지 않고
			// page_navigation 등을 바로 사용하므로, 에러 객체가 아니라 "성공한 빈 결과"로 반환해야
			// 스킨에서 page_navigation null 오류가 발생하지 않는다.
			$obj->use_alternate_output = ElasticModel::emptyResult(
				$obj,
				$config->guest_message ?: Context::getLang('msg_es_search_guest_blocked')
			);
			return;
		}

		// 상담 게시판 글도 일반 글과 동일하게 ES 검색을 거친다. "관리자가 아니면 자기 글만"
		// 제약은 board.view.php가 이미 $args->member_srl을 좁혀서 넘겨주는 것에 의존한다 -
		// 클래스 docblock의 "상담 게시판 노출 범위 전제" 설명을 참고할 것.
		try
		{
			$obj->use_alternate_output = ElasticModel::search($obj);
		}
		catch (\Throwable $e)
		{
			// ES 장애 시 기존 DB 검색으로 자동 전환한다 (use_alternate_output을 설정하지 않음).
			return;
		}
	}

	/**
	 * document.insertDocument (after)
	 *
	 * @param object $obj
	 */
	public function afterInsertDocument($obj)
	{
		$this->markPendingIfIndexable($obj);
	}

	/**
	 * document.updateDocument (after)
	 *
	 * @param object $obj
	 */
	public function afterUpdateDocument($obj)
	{
		$this->markPendingIfIndexable($obj);
	}

	/**
	 * document.deleteDocument (after)
	 *
	 * @param object $obj
	 */
	public function afterDeleteDocument($obj)
	{
		if (empty($obj->document_srl) || empty($obj->module_srl))
		{
			return;
		}
		SearchLogModel::markPending((int)$obj->module_srl, (int)$obj->document_srl, 'delete');
	}

	/**
	 * comment.insertComment (after)
	 *
	 * @param object $obj
	 */
	public function afterInsertComment($obj)
	{
		$this->markCommentPendingIfIndexable($obj);
	}

	/**
	 * comment.updateComment (after)
	 *
	 * @param object $obj
	 */
	public function afterUpdateComment($obj)
	{
		$this->markCommentPendingIfIndexable($obj);
	}

	/**
	 * comment.deleteComment (after)
	 *
	 * 댓글 한 건의 실제 삭제뿐 아니라, 내용이 "삭제된 댓글입니다"로 바뀌는 소프트 삭제
	 * (updateCommentByDelete)도 이 트리거를 거치므로 두 경우 모두 색인에서 제거한다.
	 *
	 * @param object $obj CommentItem
	 */
	public function afterDeleteComment($obj)
	{
		if (empty($obj->comment_srl))
		{
			return;
		}
		SearchLogModel::markCommentPending((int)($obj->module_srl ?? 0), (int)($obj->document_srl ?? 0), (int)$obj->comment_srl, 'delete');
	}

	/**
	 * comment.moveCommentToTrash (after)
	 *
	 * 휴지통으로 이동된 댓글은 comments 테이블에서 바로 삭제되므로(comment.deleteComment
	 * 트리거를 거치지 않음) 별도로 색인 제거를 처리해야 한다.
	 *
	 * @param object $obj
	 */
	public function afterMoveCommentToTrash($obj)
	{
		if (empty($obj->comment_srl))
		{
			return;
		}
		SearchLogModel::markCommentPending((int)($obj->module_srl ?? 0), (int)($obj->document_srl ?? 0), (int)$obj->comment_srl, 'delete');
	}

	/**
	 * comment.getTotalCommentList (before)
	 *
	 * 내 댓글 검색(dispMemberOwnComment)처럼 content 검색으로 댓글 자체를 나열하는
	 * 요청을 ES로 대체한다. email/homepage/ipaddress 등 ES에 색인하지 않은 항목으로
	 * 검색하는 요청(주로 관리자 화면)은 그대로 기존 DB 검색으로 둔다.
	 *
	 * @param object $args
	 * @return void
	 */
	public function beforeGetTotalCommentList($args)
	{
		$config = ConfigModel::getConfig();
		if (empty($config->enabled))
		{
			return;
		}

		$keyword = trim((string)($args->s_content ?? ''));
		if ($keyword === '')
		{
			return;
		}

		// ES 댓글 색인에 없는 조건으로 검색하는 요청은 기존 DB 검색으로 그대로 둔다.
		$unsupported_filters = [
			's_user_id', 's_user_name', 's_nick_name', 's_email_address', 's_homepage',
			's_regdate', 's_last_upate', 's_ipaddress', 's_is_secret', 's_is_published',
			'statusList', 'document_statusList',
		];
		foreach ($unsupported_filters as $filter)
		{
			if (!empty($args->$filter))
			{
				return;
			}
		}

		// 상담 게시판 댓글도 일반 댓글과 동일하게 ES 검색을 거친다. "관리자가 아니면 자기
		// 댓글만" 제약은 호출부가 이미 s_member_srl을 좁혀서 넘겨주는 것에 의존한다 - 클래스
		// docblock의 "상담 게시판 노출 범위 전제" 설명을 참고할 것.
		try
		{
			$args->use_alternate_output = ElasticModel::searchMemberComments($args, $keyword);
		}
		catch (\Throwable $e)
		{
			// ES 장애 시 기존 DB 검색으로 자동 전환한다.
			return;
		}
	}

	/**
	 * moduleHandler.init (after)
	 *
	 * 매 요청마다 가볍게 확인하여, 일정 주기가 지났으면 대기 중인 색인 작업을
	 * 소량 처리한다 (별도의 crontab 없이도 동작하는 소프트 스케줄러).
	 *
	 * @param object $obj
	 */
	public function afterModuleHandlerInit($obj)
	{
		$config = ConfigModel::getConfig();
		if (empty($config->enabled))
		{
			return;
		}

		$interval = max(ConfigModel::MIN_SYNC_INTERVAL_SECONDS, (int)$config->sync_interval_seconds);
		$last_run = (int)($config->last_sync_run ?? 0);
		if (time() - $last_run < $interval)
		{
			return;
		}

		$config->last_sync_run = time();
		ConfigModel::setConfig($config);

		self::processPendingLogs((int)$config->sync_batch_size);
	}

	/**
	 * 문서가 색인 대상(공개글/비밀글)이면 동기화 대기 상태로 기록한다.
	 *
	 * @param object $obj
	 * @return void
	 */
	protected function markPendingIfIndexable($obj): void
	{
		if (empty($obj->document_srl) || empty($obj->module_srl))
		{
			return;
		}

		$indexable_statuses = [DocumentModel::getConfigStatus('public'), DocumentModel::getConfigStatus('secret')];
		if (isset($obj->status) && !in_array($obj->status, $indexable_statuses))
		{
			return;
		}

		// 상담 게시판 글도 색인한다 (글마다 비공개이지만, "작성 글 보기"에서 본인 글을 찾을 수
		// 있도록 - 일반 게시판 검색에는 노출되지 않게 ElasticModel::search() 쪽에서 따로 막는다).
		SearchLogModel::markPending((int)$obj->module_srl, (int)$obj->document_srl, 'update');
	}

	/**
	 * 댓글이 색인 대상이면 동기화 대기 상태로 기록한다. 상담 게시판 댓글도 색인 대상이다
	 * ("내 댓글 보기"에서 찾을 수 있도록 - 일반 검색 노출 차단은 검색 시점에 따로 막는다).
	 *
	 * insertComment/updateComment 시점에는 status(발행 여부)가 항상 신뢰할 수 있는 값으로
	 * 들어있지 않으므로(updateComment는 status를 건드리지 않아 트리거 obj에 없음), 실제
	 * 발행 상태 확인은 processPendingLogs()에서 댓글을 다시 읽어와 판단한다.
	 *
	 * @param object $obj
	 * @return void
	 */
	protected function markCommentPendingIfIndexable($obj): void
	{
		if (empty($obj->comment_srl) || empty($obj->document_srl))
		{
			return;
		}

		SearchLogModel::markCommentPending((int)($obj->module_srl ?? 0), (int)$obj->document_srl, (int)$obj->comment_srl, 'update');
	}

	/**
	 * 게시판이 상담(consultation) 게시판인지 확인한다.
	 * 상담 게시판의 글은 작성자/관리자만 열람 가능하므로 ES 색인/검색 대상에서 제외해야 한다.
	 *
	 * @param int $module_srl
	 * @return bool
	 */
	public static function isConsultationModule(int $module_srl): bool
	{
		if (!$module_srl)
		{
			return false;
		}
		$module_info = ModuleModel::getModuleInfoByModuleSrl($module_srl);
		return isset($module_info->consultation) && $module_info->consultation === 'Y';
	}

	/**
	 * 상담 게시판으로 설정된 모든 게시판의 module_srl 목록을 가져온다.
	 *
	 * 현재는 reindex.php의 --consultation-only 옵션에서만 사용한다 (검색 시점에는 상담
	 * 게시판을 따로 가려내지 않음 - 클래스 docblock의 "상담 게시판 노출 범위 전제" 참고).
	 *
	 * consultation 설정값은 module_part_config가 아니라 module_extra_vars 테이블에
	 * 저장된다(게시판 설정 화면에서 보드 모듈의 모듈 설정으로 저장됨 - isConsultationModule()이
	 * 쓰는 ModuleModel::getModuleInfoByModuleSrl()과 같은 경로). 같은 PHP 요청 안에서
	 * 반복 조회하지 않도록 결과를 캐시한다.
	 *
	 * @return int[]
	 */
	public static function getConsultationModuleSrls(): array
	{
		static $cache = null;
		if ($cache === null)
		{
			$cache = [];
			$board_modules = ModuleModel::getMidList((object)['module' => 'board']) ?: [];
			$board_module_srls = array_map(fn($row) => (int)$row->module_srl, $board_modules);
			if ($board_module_srls)
			{
				$extra_vars = ModuleModel::getModuleExtraVars($board_module_srls);
				foreach ($extra_vars as $module_srl => $vars)
				{
					if (isset($vars->consultation) && $vars->consultation === 'Y')
					{
						$cache[] = (int)$module_srl;
					}
				}
			}
		}
		return $cache;
	}

	/**
	 * 대기 중인 색인 작업을 일정량 처리한다.
	 * 관리자 수동 동기화와 소프트 스케줄러가 공통으로 사용한다.
	 *
	 * 문서 하나마다 ES에 따로 요청을 보내면(색인 1건 = HTTP 요청 1번) 대량 처리 시 매우
	 * 느려지므로, DocumentModel::getDocuments()로 한 번에 묶어 가져오고 ES Bulk API로
	 * 한 번에 보낸다.
	 *
	 * @param int $limit
	 * @return int 처리한 건수
	 */
	public static function processPendingLogs(int $limit = 20): int
	{
		$logs = SearchLogModel::getPendingLogs($limit);
		if (!$logs)
		{
			return 0;
		}

		$document_logs = [];
		$comment_logs = [];
		foreach ($logs as $log)
		{
			if (($log->item_type ?? 'document') === 'comment')
			{
				$comment_logs[] = $log;
			}
			else
			{
				$document_logs[] = $log;
			}
		}

		if ($document_logs)
		{
			self::processPendingDocumentLogs($document_logs);
		}
		if ($comment_logs)
		{
			self::processPendingCommentLogs($comment_logs);
		}

		return count($logs);
	}

	/**
	 * processPendingLogs()의 문서 처리 부분.
	 *
	 * @param array $logs item_type이 'document'인 로그들
	 * @return void
	 */
	protected static function processPendingDocumentLogs(array $logs): void
	{
		$delete_srls = [];
		$update_srls = [];
		foreach ($logs as $log)
		{
			$document_srl = (int)$log->document_srl;
			if ($log->action === 'delete')
			{
				$delete_srls[] = $document_srl;
			}
			else
			{
				$update_srls[] = $document_srl;
			}
		}

		// 색인할 문서를 한 번에 묶어서 가져온다 (문서당 쿼리 한 번씩 날리지 않도록).
		$documents_map = $update_srls ? DocumentModel::getDocuments($update_srls) : [];
		$documents_to_index = [];
		foreach ($update_srls as $document_srl)
		{
			if (isset($documents_map[$document_srl]))
			{
				$documents_to_index[] = $documents_map[$document_srl];
			}
			else
			{
				// 이미 삭제/이동되어 더 이상 존재하지 않는 문서는 색인에서도 지운다.
				$delete_srls[] = $document_srl;
			}
		}

		try
		{
			$results = ElasticModel::bulkSync($documents_to_index, $delete_srls);
			foreach ($logs as $log)
			{
				$document_srl = (int)$log->document_srl;
				if (!array_key_exists($document_srl, $results))
				{
					// 대량 Bulk 요청 처리 중 응답이 일부 잘려서 해당 문서의 결과를 못 받은 경우다.
					// (실제로 색인이 실패한 게 아니라 응답 누락일 뿐이라 재시도하면 대부분 성공한다.)
					// 그대로 pending 상태로 두면 다음 동기화 때 자동으로 다시 처리된다.
					continue;
				}
				$error = $results[$document_srl];
				SearchLogModel::markResult((int)$log->log_srl, $error === null, (string)$error);
			}
		}
		catch (\Throwable $e)
		{
			foreach ($logs as $log)
			{
				SearchLogModel::markResult((int)$log->log_srl, false, $e->getMessage());
			}
		}
	}

	/**
	 * processPendingLogs()의 댓글 처리 부분.
	 *
	 * 댓글은 insertComment/updateComment 시점에 발행 상태를 신뢰할 수 없어 일단 모두
	 * pending으로 등록되므로(markCommentPendingIfIndexable() 참고), 여기서 댓글을 다시
	 * 읽어와 실제로 색인 가능한 상태(RX_STATUS_PUBLIC)인지 확인한다. 또한 댓글 테이블에는
	 * category_srl이 없으므로 글(문서)에서 가져와 함께 색인하고, 글이 비밀글이면 댓글
	 * 자신의 is_secret 값과 무관하게 비밀댓글로 취급해 색인한다(비밀글에 달린 댓글은
	 * 비밀댓글로 간주).
	 *
	 * @param array $logs item_type이 'comment'인 로그들
	 * @return void
	 */
	protected static function processPendingCommentLogs(array $logs): void
	{
		$delete_srls = [];
		$update_srls = [];
		foreach ($logs as $log)
		{
			$comment_srl = (int)$log->comment_srl;
			if ($log->action === 'delete')
			{
				$delete_srls[] = $comment_srl;
			}
			else
			{
				$update_srls[] = $comment_srl;
			}
		}

		$comments_map = $update_srls ? CommentModel::getComments($update_srls) : [];
		$comments_to_index = [];
		foreach ($update_srls as $comment_srl)
		{
			if (!isset($comments_map[$comment_srl]) || (int)$comments_map[$comment_srl]->get('status') !== \RX_STATUS_PUBLIC)
			{
				// 삭제/이동되었거나 아직 발행되지 않은(또는 삭제 처리된) 댓글은 색인에서 지운다.
				$delete_srls[] = $comment_srl;
			}
			else
			{
				$comments_to_index[] = $comments_map[$comment_srl];
			}
		}

		// category_srl을 채우기 위해 글을 한 번에 묶어서 가져온다. 글이 삭제되었거나 더 이상
		// 색인 가능한 상태(공개/비밀)가 아니면 댓글도 같이 색인에서 지운다.
		$document_srls = [];
		foreach ($comments_to_index as $oComment)
		{
			$document_srls[(int)$oComment->get('document_srl')] = true;
		}
		$secret_status = DocumentModel::getConfigStatus('secret');
		$indexable_statuses = [DocumentModel::getConfigStatus('public'), $secret_status];
		$documents_map = $document_srls ? DocumentModel::getDocuments(array_keys($document_srls), false, false, ['document_srl', 'category_srl', 'status']) : [];

		$document_info_map = [];
		foreach ($comments_to_index as $key => $oComment)
		{
			$document_srl = (int)$oComment->get('document_srl');
			$oDocument = $documents_map[$document_srl] ?? null;
			if (!$oDocument || !in_array($oDocument->get('status'), $indexable_statuses))
			{
				unset($comments_to_index[$key]);
				$delete_srls[] = (int)$oComment->comment_srl;
				continue;
			}
			$document_info_map[$document_srl] = [
				'category_srl' => (int)$oDocument->get('category_srl'),
				'secret' => $oDocument->get('status') === $secret_status,
			];
		}

		try
		{
			$results = ElasticModel::bulkSyncComments($comments_to_index, $document_info_map, $delete_srls);
			foreach ($logs as $log)
			{
				$comment_srl = (int)$log->comment_srl;
				if (!array_key_exists($comment_srl, $results))
				{
					continue;
				}
				$error = $results[$comment_srl];
				SearchLogModel::markResult((int)$log->log_srl, $error === null, (string)$error);
			}
		}
		catch (\Throwable $e)
		{
			foreach ($logs as $log)
			{
				SearchLogModel::markResult((int)$log->log_srl, false, $e->getMessage());
			}
		}
	}
}
