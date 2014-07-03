<?php
ini_set('default_socket_timeout', -1);
date_default_timezone_set('Europe/Moscow');
require_once('localConfig.php');

class Daemon{
	public $timeStart=0;
	public $redis;
	public $stopDaemon=false;
	public $timeLoop=1000000; // 1 секунда
	public $task=false;
	public $counterTask=0;

    public function __construct() {
        $this->timeStart=time();
        $this->redisConnect();
        echo date('d.m.Y H:i:s')." start\n";
        $this->loop();
    }

	// подключение к redis ===================================
    public function redisConnect() {
    	unset($this->redis);
		$this->redis = new Redis();
		$res=$this->redis->pconnect('/tmp/redis.sock');
		if(!$res){
			echo date('d.m.Y H:i:s').' [err] REDIS: Нет подключения!'.PHP_EOL;
    		$this->timeLoop=3000000; // 3сек.
    		return;
		}
		$this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
		$this->redis->select(0);
    }

    // Чтобы не плодить зомби ==============================
    public function checkDieChild() {
    	do{
	    	pcntl_signal_dispatch();
	    	$pid=pcntl_waitpid(-1, $status, WNOHANG);
	    } while($pid>0);
    }

    // главный цикл ========================================
    public function loop() {
    	while(!$this->stopDaemon){
    		$this->checkDieChild();
    		$this->getTask();
    		usleep($this->timeLoop);
    	}
    }

    // получить задание ======================================
    public function getTask(){
    	try{
    		$this->task=$this->redis->lPop('task');	
    	} catch(RedisException $e){
    		$this->redisConnect();
    		echo date('d.m.Y H:i:s').' [err] REDIS: '.$e->getMessage().PHP_EOL;
    		return;
    	}
    	
    	if($this->task===false){
    		// заданий нет - увеличим время
    		$this->timeLoop=2000000; // 2 секунды
    		return;
    	}

		$this->timeLoop=0; // почти нет задержки
		$this->setTask();
    }

    // Показать статус ======================================
    public function status(){
        $status=array(
            'pid'=>getmypid(),
            'gid'=>getmygid(),
            'memory_get_usage' => memory_get_usage(true),
            'memory_get_peak_usage' => memory_get_peak_usage(true),
            'count_task' => $this->counterTask,
        );
        var_dump($status);
    }

    // поcтавить задание ======================================
    public function setTask(){
    	$this->counterTask++; // счетчик задач

    	if($this->task=='status' || @$this->task['queue_action']=='status'){
    		$this->status();
    		return;
    	}
    	
    	// закроем подключение, чтобы форк его не получил
    	try{
    		$this->redis->close();
    	} catch(RedisException $e){
    		echo date('d.m.Y H:i:s').' [err] REDIS: '.$e->getMessage().PHP_EOL;
    	}
		$pid=pcntl_fork();
		if($pid!=0){ // этот кусок отработает только в мастер процессе
			$this->redisConnect();
			$this->task=NULL; // обнулили задачу для мастера
			// мастер - процесс вышел из функции
			return;
		}

		// здесь потомок
		#posix_setsid(); // если мастер сдох - потомок должен продолжить отрабатывать задачу
		$this->work();
		$this->stopDaemon=true; // после выполнения работы - потомок должен умереть
    }

    // worker обрабатывает задачу ======================================
    public function work(){
    	if(@$this->task['queue_action']=='info'){
    		var_dump($this->task);
    		return;
    	}
    }    
}

$daemon=new Daemon();
die;
