<?php
namespace Godv;

/**
 * 文件队列
 * @author godv <497012571@qq.com>
 */
class FileQueue
{
	/**
	 * 最大读取数据数
	 */
	const READ_SIZE = 65535;

	/**
	 * 默认的配置文件目录名称
	 * @var string
	 */
	const CONFIG_DIRNAME = '.file-queue/';
	
	/**
	 * 缓存键配置
	 * @var array
	 */
	protected $cacheKeys = [];

	/**
	 * 数据库路径
	 * @var string
	 */
	protected $databaseRoot;
	
	/**
	 * 配置文件路径
	 * @var string
	 */
	protected $configPath;

	/**
	 * key文件句柄
	 * @var resource
	 */
	protected $fp;

	/**
	 * 键配置文件
	 * @var string
	 */
	protected $keyfile;

	public function __construct($config = [])
	{
		if (empty($config['databaseRoot'])) {
			throw new \Exception("请设置: databseRoot");
		}

		$this->databaseRoot = $config['databaseRoot'] . '/';
		if (!is_dir($this->databaseRoot)) {
			mkdir($this->databaseRoot, 0755, true);
		}

		$this->configPath = $this->databaseRoot . self::CONFIG_DIRNAME;
		if (!is_dir($this->configPath)) {
			mkdir($this->configPath, 0755, true);
		}
		
		$this->initKeys();
	}

	public function __destruct()
	{
		foreach ($this->cacheKeys as $key => $value) {
			fclose($value['fp']);
		}
	}

	/**
	 * 初始化key
	 */
	public function initKeys()
	{
		foreach(glob($this->configPath . '*') as $keyfile) {
			$basename = basename($keyfile);
			$key = substr($basename, 0, strrpos($basename, '.'));
			$this->cacheKeys[$key]['fp'] = fopen($keyfile, 'r+');
			$this->cacheKeys[$key]['keyfile'] = $keyfile;
		}
	}

	/**
	 * 切换操作key的上下文
	 * @param  string $key [description]
	 * @return void
	 */
	public function contextKey($key)
	{
		if (isset($this->cacheKeys[$key])) {
			$this->keyfile = $this->cacheKeys[$key]['keyfile'];
			$this->fp = $this->cacheKeys[$key]['fp'];
			return ;
		}
		$this->keyfile = $this->configPath . $key . '.key';
		if (!is_file($this->keyfile)) {
			touch($this->keyfile) && chmod($this->keyfile, 0755);
		}
		$this->fp = fopen($this->keyfile, 'r+');
		$this->cacheKeys[$key]['keyfile'] = $this->keyfile;
		$this->cacheKeys[$key]['fp'] = $this->fp;
	}

	/**
	 * 设置配置文件
	 * @param string $k  键
	 * @param string $v  值
	 * @return void
	 */
	public function setConfig($k, $v)
	{
		$config = parse_ini_file($this->keyfile, true);
		$config[$k] = $v;

		$content = '';
		foreach ($config as $_k => $_v) {
			if (!is_null($_v)) {
				$content .= "$_k = $_v\n";
			}
		}
		if ($content) {
			rewind($this->fp);
			fwrite($this->fp, $content);
		}
	}

	/**
	 * 获取key的递减索引
	 * @param  $key 键名
	 * @return integer
	 */
	public function getIncrement($key)
	{
		$config = parse_ini_file($this->keyfile, true);
		if (isset($config['key-increment'])) {
			return $config['key-increment'] + 1;
		}
		return 1;
	}

	/**
	 * 获取指定key数量
	 * @param  string $key 键
	 * @return integer
	 */
	public function getCount($key)
	{
		if (!$this->hasKey($key)) {
			return 0;
		}
		$config = parse_ini_file($this->keyfile, true);
		if (!isset($config['key-count'])) {
			return 0;
		}
		return $config['key-count'];
	}

	/**
	 * key是否存在
	 * @param  string  $key 键名
	 * @return boolean
	 */
	private function hasKey($key)
	{
		return is_file($this->configPath . $key . '.key');
	}

	/**
	 * 入队
	 * @param  string $key   键名
	 * @param  string $value 值
	 * @return true | false
	 */
	public function push($key,  $value)
	{
		$this->contextKey($key);
		do {

			if (($handle = flock($this->fp, LOCK_EX | LOCK_NB)) === false) {
				usleep(rand(10,500));
				continue;
			}

			$keyDir = $this->databaseRoot . $key . '/';
			if (!is_dir($keyDir)) {
				mkdir($keyDir,0755);
				$this->setConfig('key-increment', 0);
				$this->setConfig('key-count', 0);
			}

			$inc = $this->getIncrement($key);
			if(file_put_contents($keyDir . $inc, $value)){
				$this->setConfig('key-increment', $inc);
				$this->setConfig('key-popindex', $inc);
				$this->setConfig('key-count', $this->getCount($key) + 1);
			}
			
			flock($this->fp, LOCK_UN);

		} while(!$handle);
	}
	
	/**
	 * 出队
	 * @param  string $key 键名
	 * @param  boolean $locknb 是否阻塞
	 * @return string|null
	 */
	public function pop($key)
	{
		$this->contextKey($key);

		$keyDir = $this->databaseRoot . $key . '/';

		if (!is_dir($keyDir) || !$this->hasKey($key)) {
			return false;
		}

		$value = false;
		do {

			if (($handle = flock($this->fp, LOCK_EX | LOCK_NB)) === false) {
				usleep(rand(1000,2000));
				continue;
			}

			$config = parse_ini_file($this->keyfile, true);

			$index = $config['key-popindex'];

			while ($index > 0) {
				if (is_file($keyDir . $index) ) {
					$fd = @fopen($keyDir . $index, 'r');
					$value = fread($fd, self::READ_SIZE);
					fclose($fd);
					unlink($keyDir . $index);
					$this->setConfig('key-popindex', $index - 1);
					$this->setConfig('key-count', $config['key-count'] - 1);
					break;
				}
				$index--;
			}

			flock($this->fp, LOCK_UN);

		} while (!$handle);

		return $value;
	}

	/**
	 * 指定删除队列KEY
	 * @param string $key 键名
	 * @return true
	 */
	public function remove($key)
	{
		if (!$this->hasKey($key)) {
			return false;
		}
		$this->contextKey($key);
		fclose($this->fp);
		unset($this->cacheKeys[$key]);
		array_map('unlink', glob($this->databaseRoot . $key . '/*'));
		unlink($this->keyfile);
		rmdir($this->databaseRoot . $key);
		return true;
	}

	/**
	 * 清空所有数据
	 * @return void
	 */
	public function removeAll()
	{
		array_map([$this, 'remove'], array_keys($this->cacheKeys));
	}
	
}