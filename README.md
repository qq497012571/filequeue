# filequeue
一个超小型的本地持久化文件队列数据库类,保证单机并发下数据的原子性

# 安装

>composer require godv/filequeue 0.1

# 示例

```	php
// 初始化
$queue = new \Godv\FileQueue([
	'databaseRoot' => './file-queue/test' //设置文件存储路径,没有会尝试自动创建
]);

// 入队
$queue->push('name', 'jianglibin');
$queue->push('name', 'godv');

// 获取key数量
echo $queue->getCount('name'); // 2

// 出队
echo $queue->pop('name'),"\n"; // godv

// 获取key数量
echo $queue->getCount('name'); // 1


//$queue->remove('name'); // 销毁key

// 销毁队列全部数据
$queue->removeAll();

```

# api列表

* `FileQueue::push` 入队
* `FileQueue::pop` 出队
* `FileQueue::remove` 删除指定key
* `FileQueue::removeAll` 删除全部key
* `FileQueue::getCount` 获取某个key数量