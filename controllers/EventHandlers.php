<?php

namespace Rhymix\Modules\Es_search\Controllers;

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
 */
class EventHandlers extends Base
{
	/**
	 * 검색 가능한 search_target 목록 (제목/본문/회원번호/별명/사용자 ID/등록일 검색을 ES로 대체한다).
	 */
	protected const SEARCHABLE_TARGETS = ['title', 'content', 'title_content', 'member_srl', 'nick_name', 'user_id', 'regdate'];

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

		// 상담 게시판은 글마다 작성자/관리자에게만 공개되므로, ES를 거치지 않고
		// 기존 DB 검색(글쓴이 기준 필터링이 포함된)으로 그대로 처리한다.
		foreach ((array)($obj->module_srl ?? []) as $module_srl)
		{
			if (self::isConsultationModule((int)$module_srl))
			{
				return;
			}
		}

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

		// 상담 게시판 글은 작성자/관리자 외에는 비공개이므로 ES에 색인하지 않는다.
		if (self::isConsultationModule((int)$obj->module_srl))
		{
			return;
		}

		SearchLogModel::markPending((int)$obj->module_srl, (int)$obj->document_srl, 'update');
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

		$delete_srls = [];
		$update_srls = [];
		foreach ($logs as $log)
		{
			$document_srl = (int)$log->document_srl;
			if ($log->action === 'delete' || self::isConsultationModule((int)$log->module_srl))
			{
				// 상담 게시판 글은 (만약 이전에 잘못 등록되었더라도) 색인하지 않고 제거한다.
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

		return count($logs);
	}
}
