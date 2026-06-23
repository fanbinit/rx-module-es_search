<?php

namespace Rhymix\Modules\Es_search\Models;

use DB;
use executeQuery;
use executeQueryArray;

/**
 * 색인 동기화 로그(es_search_log) 관리 모델.
 */
class SearchLog
{
	/**
	 * 문서 변경 사항을 동기화 대기 상태로 기록한다.
	 * 같은 문서에 대해 이미 대기 중인 항목이 있으면 지우고 다시 쌓는다 (중복 처리 방지).
	 *
	 * @param int $module_srl
	 * @param int $document_srl
	 * @param string $action insert|update|delete
	 * @return void
	 */
	public static function markPending(int $module_srl, int $document_srl, string $action): void
	{
		$delete_args = new \stdClass;
		$delete_args->document_srl = $document_srl;
		executeQuery('es_search.deletePendingByDocument', $delete_args);

		$args = new \stdClass;
		$args->log_srl = getNextSequence();
		$args->module_srl = $module_srl;
		$args->document_srl = $document_srl;
		$args->action = $action;
		$args->status = 'pending';
		$args->regdate = date('YmdHis');
		executeQuery('es_search.insertLog', $args);
	}

	/**
	 * markPending()의 배치 버전. 여러 문서를 트랜잭션 하나로 묶어 등록한다.
	 * 재색인 스캔처럼 한 번에 수백~수천 건을 등록할 때, 매번 따로 커밋되는
	 * markPending()을 반복 호출하는 것보다 훨씬 빠르다.
	 *
	 * @param array $rows 각 항목은 ['module_srl' => int, 'document_srl' => int, 'action' => string]
	 * @return void
	 */
	public static function markPendingBatch(array $rows): void
	{
		if (!$rows)
		{
			return;
		}

		$document_srls = array_map(fn($row) => (int)$row['document_srl'], $rows);

		$oDB = DB::getInstance();
		$oDB->begin();

		$delete_args = new \stdClass;
		$delete_args->document_srls = $document_srls;
		executeQuery('es_search.deletePendingByDocuments', $delete_args);

		$regdate = date('YmdHis');
		foreach ($rows as $row)
		{
			$args = new \stdClass;
			$args->log_srl = getNextSequence();
			$args->module_srl = (int)$row['module_srl'];
			$args->document_srl = (int)$row['document_srl'];
			$args->action = $row['action'];
			$args->status = 'pending';
			$args->regdate = $regdate;
			executeQuery('es_search.insertLog', $args);
		}

		$oDB->commit();
	}

	/**
	 * 댓글 변경 사항을 동기화 대기 상태로 기록한다. markPending()의 댓글 버전.
	 *
	 * 댓글은 document_srl이 같아도(=같은 글에 달린 여러 댓글) 별개로 색인해야 하므로
	 * comment_srl로 중복 처리한다. item_type='comment'로 구분해 같은 테이블을 문서용
	 * 로그와 함께 쓰면서도 document_srl 값 충돌(우연히 같은 번호) 없이 동작한다.
	 *
	 * @param int $module_srl
	 * @param int $document_srl
	 * @param int $comment_srl
	 * @param string $action insert|update|delete
	 * @return void
	 */
	public static function markCommentPending(int $module_srl, int $document_srl, int $comment_srl, string $action): void
	{
		$delete_args = new \stdClass;
		$delete_args->comment_srl = $comment_srl;
		executeQuery('es_search.deletePendingByComment', $delete_args);

		$args = new \stdClass;
		$args->log_srl = getNextSequence();
		$args->module_srl = $module_srl;
		$args->document_srl = $document_srl;
		$args->comment_srl = $comment_srl;
		$args->item_type = 'comment';
		$args->action = $action;
		$args->status = 'pending';
		$args->regdate = date('YmdHis');
		executeQuery('es_search.insertLog', $args);
	}

	/**
	 * markCommentPending()의 배치 버전. 재색인 스캔에서 사용한다.
	 *
	 * @param array $rows 각 항목은 ['module_srl' => int, 'document_srl' => int, 'comment_srl' => int, 'action' => string]
	 * @return void
	 */
	public static function markCommentPendingBatch(array $rows): void
	{
		if (!$rows)
		{
			return;
		}

		$comment_srls = array_map(fn($row) => (int)$row['comment_srl'], $rows);

		$oDB = DB::getInstance();
		$oDB->begin();

		$delete_args = new \stdClass;
		$delete_args->comment_srls = $comment_srls;
		executeQuery('es_search.deletePendingByComments', $delete_args);

		$regdate = date('YmdHis');
		foreach ($rows as $row)
		{
			$args = new \stdClass;
			$args->log_srl = getNextSequence();
			$args->module_srl = (int)$row['module_srl'];
			$args->document_srl = (int)$row['document_srl'];
			$args->comment_srl = (int)$row['comment_srl'];
			$args->item_type = 'comment';
			$args->action = $row['action'];
			$args->status = 'pending';
			$args->regdate = $regdate;
			executeQuery('es_search.insertLog', $args);
		}

		$oDB->commit();
	}

	/**
	 * 전체 재색인을 위해, comment_srl 기준으로 댓글 목록을 배치 단위로 가져온다 (keyset pagination).
	 *
	 * @param int $status 댓글 status 컬럼 값 (RX_STATUS_PUBLIC 등)
	 * @param int $from_comment_srl 이 값보다 큰 comment_srl부터 조회
	 * @param int $batch_size
	 * @param int|int[]|null $module_srl 지정하면 해당 게시판만 (배열로 여러 게시판도 가능)
	 * @return array
	 */
	public static function getCommentBatch(int $status, int $from_comment_srl, int $batch_size, $module_srl = null): array
	{
		$args = new \stdClass;
		$args->status = $status;
		$args->from_comment_srl = $from_comment_srl;
		$args->list_count = $batch_size;
		if ($module_srl)
		{
			$args->module_srl = $module_srl;
		}
		$output = executeQueryArray('es_search.getAllCommentSrls', $args);
		return $output->toBool() ? ($output->data ?: []) : [];
	}

	/**
	 * getCommentBatch()와 동일한 조건에 해당하는 댓글의 전체 개수를 가져온다.
	 *
	 * @param int $status
	 * @param int|int[]|null $module_srl
	 * @return int
	 */
	public static function getCommentCount(int $status, $module_srl = null): int
	{
		$args = new \stdClass;
		$args->status = $status;
		if ($module_srl)
		{
			$args->module_srl = $module_srl;
		}
		$output = executeQuery('es_search.getCommentCount', $args);
		return $output->toBool() ? (int)($output->data->count ?? 0) : 0;
	}

	/**
	 * 동기화 대기 중인 로그 목록을 가져온다.
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function getPendingLogs(int $limit = 50): array
	{
		$args = new \stdClass;
		$args->status = 'pending';
		$args->list_count = $limit;
		$output = executeQueryArray('es_search.getPendingLogs', $args);
		return $output->toBool() ? ($output->data ?: []) : [];
	}

	/**
	 * 동기화 대기 중인 로그 수를 가져온다.
	 *
	 * @return int
	 */
	public static function getPendingCount(): int
	{
		$args = new \stdClass;
		$args->status = 'pending';
		$output = executeQuery('es_search.getPendingCount', $args);
		return $output->toBool() ? (int)($output->data->count ?? 0) : 0;
	}

	/**
	 * 실패로 표시된 항목을 모두 대기(pending) 상태로 되돌려 재시도되게 한다.
	 * Bulk 응답이 일부 누락되는 등 일시적인 원인으로 실패한 경우 재시도하면 대부분 성공한다.
	 *
	 * @return void
	 */
	public static function requeueFailed(): void
	{
		$args = new \stdClass;
		$args->new_status = 'pending';
		$args->old_status = 'failed';
		executeQuery('es_search.requeueFailed', $args);
	}

	/**
	 * 최근 처리 실패한 로그 수를 가져온다.
	 *
	 * @return int
	 */
	public static function getFailedCount(): int
	{
		$args = new \stdClass;
		$args->status = 'failed';
		$output = executeQuery('es_search.getPendingCount', $args);
		return $output->toBool() ? (int)($output->data->count ?? 0) : 0;
	}

	/**
	 * 색인에 성공한(done) 로그 수를 가져온다.
	 *
	 * @return int
	 */
	public static function getDoneCount(): int
	{
		$args = new \stdClass;
		$args->status = 'done';
		$output = executeQuery('es_search.getPendingCount', $args);
		return $output->toBool() ? (int)($output->data->count ?? 0) : 0;
	}

	/**
	 * item_type(document|comment)과 status별 로그 수를 가져온다 (관리자 화면의 분리 표시용).
	 *
	 * @param string $item_type document|comment
	 * @param string $status pending|done|failed
	 * @return int
	 */
	public static function getCountByType(string $item_type, string $status): int
	{
		$args = new \stdClass;
		$args->item_type = $item_type;
		$args->status = $status;
		$output = executeQuery('es_search.getCountByTypeAndStatus', $args);
		return $output->toBool() ? (int)($output->data->count ?? 0) : 0;
	}

	/**
	 * 최근 로그 목록을 가져온다 (관리자 화면 표시용).
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function getRecentLogs(int $limit = 50): array
	{
		$args = new \stdClass;
		$args->list_count = $limit;
		$output = executeQueryArray('es_search.getRecentLogs', $args);
		return $output->toBool() ? ($output->data ?: []) : [];
	}

	/**
	 * 전체 재색인을 위해, document_srl 기준으로 문서 목록을 배치 단위로 가져온다 (keyset pagination).
	 *
	 * @param array $statusList
	 * @param int $from_document_srl 이 값보다 큰 document_srl부터 조회
	 * @param int $batch_size
	 * @param int|int[]|null $module_srl 지정하면 해당 게시판만 (배열로 여러 게시판도 가능)
	 * @return array
	 */
	public static function getDocumentBatch(array $statusList, int $from_document_srl, int $batch_size, $module_srl = null): array
	{
		$args = new \stdClass;
		$args->statusList = $statusList;
		$args->from_document_srl = $from_document_srl;
		$args->list_count = $batch_size;
		if ($module_srl)
		{
			$args->module_srl = $module_srl;
		}
		$output = executeQueryArray('es_search.getAllDocumentSrls', $args);
		return $output->toBool() ? ($output->data ?: []) : [];
	}

	/**
	 * getDocumentBatch()와 동일한 조건에 해당하는 문서의 전체 개수를 가져온다.
	 * 재색인 스크립트의 진행률 표시용.
	 *
	 * @param array $statusList
	 * @param int|int[]|null $module_srl
	 * @return int
	 */
	public static function getDocumentCount(array $statusList, $module_srl = null): int
	{
		$args = new \stdClass;
		$args->statusList = $statusList;
		if ($module_srl)
		{
			$args->module_srl = $module_srl;
		}
		$output = executeQuery('es_search.getDocumentCount', $args);
		return $output->toBool() ? (int)($output->data->count ?? 0) : 0;
	}

	/**
	 * 동기화 큐(로그)를 전부 비운다.
	 *
	 * @return void
	 */
	public static function clearAll(): void
	{
		executeQuery('es_search.deleteAllLogs', new \stdClass);
	}

	/**
	 * 처리 결과를 기록한다.
	 *
	 * @param int $log_srl
	 * @param bool $success
	 * @param string $message
	 * @return void
	 */
	public static function markResult(int $log_srl, bool $success, string $message = ''): void
	{
		$args = new \stdClass;
		$args->log_srl = $log_srl;
		$args->status = $success ? 'done' : 'failed';
		$args->message = $message;
		$args->last_update = date('YmdHis');
		executeQuery('es_search.updateLogStatus', $args);
	}
}
