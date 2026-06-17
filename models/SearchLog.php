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
	 * @param ?int $module_srl 지정하면 해당 게시판만
	 * @return array
	 */
	public static function getDocumentBatch(array $statusList, int $from_document_srl, int $batch_size, ?int $module_srl = null): array
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
	 * @param ?int $module_srl
	 * @return int
	 */
	public static function getDocumentCount(array $statusList, ?int $module_srl = null): int
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
