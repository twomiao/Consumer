### Consumer 简介：
> Pcntl 扩展实现高效率多进程消费，因此只支持Linux。进程模型类似于PHP-FPM 动态进程池。

* Consumer 特点: 
   * 多进程运用多核CPU  √
   * 消费者可记录超时任务的日志  √
   * 队列压力过大数据堆积，自动根据任务量创建临时消费者  √
   * 临时消费者缓解队列压力后，自动根据配置文件空闲时间退出节省系统资源  √
   * 临时或者常驻消费者异常退出，Master自动拉取相同类型消费者  √
   * 支持守护进程，设置用户组  √
   * 内存超出预设值，消费者处理完任务自动退出  √
   * 每个消费者完成配置任务数量，自动退出防止内存泄漏  √
   * 进程信号停止命令已完成  √
   * 全局异常捕捉父子进程异常已实现  √
   * 日志类型保存未实现  ×
  
### 环境要求

* PHP >= 7.1.0
* Pcntl