<?php

namespace Rhymix\Modules\Es_search\Models;

use ModuleController;
use ModuleModel;

class Config
{
	/**
	 * 설정값 기본값. getConfig()가 항상 이 값들을 채워서 반환하므로,
	 * 다른 곳(Elastic 모델, Admin 컨트롤러, 관리자 화면 템플릿)에서는
	 * '127.0.0.1', 20처럼 같은 기본값을 따로 하드코딩하지 않아도 된다.
	 */
	public const DEFAULTS = [
		'enabled' => false,
		'es_scheme' => 'http',
		'es_host' => '127.0.0.1',
		'es_port' => '9200',
		'es_index' => 'rhymix_documents',
		'es_username' => '',
		'es_password' => '',
		'member_only_search' => false,
		'guest_message' => null,
		'sync_batch_size' => 20,
		'sync_interval_seconds' => 300,
	];

	public const MIN_SYNC_BATCH_SIZE = 1;
	public const MAX_SYNC_BATCH_SIZE = 500;
	public const MIN_SYNC_INTERVAL_SECONDS = 60;

	protected static $_cache = null;

	public static function getConfig(): object
	{
		if (self::$_cache === null)
		{
			$config = ModuleModel::getModuleConfig('es_search') ?: new \stdClass;
			foreach (self::DEFAULTS as $key => $default)
			{
				if (!isset($config->$key))
				{
					$config->$key = $default;
				}
			}
			self::$_cache = $config;
		}
		return self::$_cache;
	}

	public static function setConfig(object $config): object
	{
		$oModuleController = ModuleController::getInstance();
		$output = $oModuleController->insertModuleConfig('es_search', $config);
		if ($output->toBool())
		{
			self::$_cache = $config;
		}
		return $output;
	}
}
