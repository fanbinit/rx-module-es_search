<?php

namespace Rhymix\Modules\Es_search\Controllers;

use Context;
use Rhymix\Modules\Es_search\Models\Config as ConfigModel;
use Rhymix\Modules\Es_search\Models\Elastic as ElasticModel;
use Rhymix\Modules\Es_search\Models\SearchLog as SearchLogModel;

class Admin extends Base
{
	/**
	 * 관리자 설정 화면
	 */
	public function dispEs_searchAdminConfig()
	{
		$config = ConfigModel::getConfig();
		Context::set('config', $config);
		Context::set('nori_installed', $config->enabled ? ElasticModel::isNoriInstalled() : null);
		Context::set('document_pending_count', SearchLogModel::getCountByType('document', 'pending'));
		Context::set('document_done_count', SearchLogModel::getCountByType('document', 'done'));
		Context::set('document_failed_count', SearchLogModel::getCountByType('document', 'failed'));
		Context::set('comment_pending_count', SearchLogModel::getCountByType('comment', 'pending'));
		Context::set('comment_done_count', SearchLogModel::getCountByType('comment', 'done'));
		Context::set('comment_failed_count', SearchLogModel::getCountByType('comment', 'failed'));

		$this->setTemplatePath($this->module_path . 'views/admin/');
		$this->setTemplateFile('config');
	}

	/**
	 * 관리자 설정 저장
	 */
	public function procEs_searchAdminSaveConfig()
	{
		$vars = Context::getRequestVars();
		$config = ConfigModel::getConfig();

		$config->enabled = ($vars->enabled === 'Y');
		$config->es_scheme = ($vars->es_scheme === 'https') ? 'https' : 'http';
		$config->es_host = trim((string)$vars->es_host) ?: $config->es_host;
		$config->es_port = trim((string)$vars->es_port) ?: $config->es_port;
		$config->es_index = trim((string)$vars->es_index) ?: $config->es_index;
		$config->es_comment_index = trim((string)$vars->es_comment_index) ?: $config->es_comment_index;
		$config->es_username = trim((string)($vars->es_username ?? ''));
		if (strlen(trim((string)($vars->es_password ?? ''))))
		{
			$config->es_password = trim((string)$vars->es_password);
		}
		$config->member_only_search = ($vars->member_only_search === 'Y');
		$config->guest_message = trim((string)$vars->guest_message) ?: null;
		$config->sync_batch_size = max(ConfigModel::MIN_SYNC_BATCH_SIZE, min(ConfigModel::MAX_SYNC_BATCH_SIZE, (int)$vars->sync_batch_size ?: $config->sync_batch_size));
		$config->sync_interval_seconds = max(ConfigModel::MIN_SYNC_INTERVAL_SECONDS, (int)$vars->sync_interval_seconds ?: $config->sync_interval_seconds);

		$output = ConfigModel::setConfig($config);
		if (!$output->toBool())
		{
			return $output;
		}

		$this->setMessage('success_registed');
		$this->setRedirectUrl(Context::get('success_return_url'));
	}

	/**
	 * 대기 중인 색인 작업을 즉시(수동으로) 처리한다.
	 */
	public function procEs_searchAdminSyncIndex()
	{
		SearchLogModel::requeueFailed();

		$config = ConfigModel::getConfig();
		$processed = EventHandlers::processPendingLogs(max(ConfigModel::MIN_SYNC_BATCH_SIZE, (int)$config->sync_batch_size * 5));

		$this->setMessage(sprintf(Context::getLang('msg_es_search_sync_done'), $processed));
		$this->setRedirectUrl(Context::get('success_return_url'));
	}

	/**
	 * ElasticSearch 색인과 동기화 큐를 모두 비운다.
	 */
	public function procEs_searchAdminFlush()
	{
		ElasticModel::flushIndex();
		ElasticModel::flushCommentIndex();
		SearchLogModel::clearAll();

		$this->setMessage(Context::getLang('msg_es_search_flush_done'));
		$this->setRedirectUrl(Context::get('success_return_url'));
	}
}
