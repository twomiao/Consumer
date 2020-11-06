<?php


namespace Consumer;


class ExitedCode
{
    // 1-9
    const FORK_ERROR   = 1; // fork 失败
    const JOB_EXIT_OUT = 3; // 超时
    const MEMORY_SIZE  = 2; // 内存使用超出限制
    const SETSID_FAIL  = 4; // 守护进程失败

    const SUCCESS = 0 ;    // 正常退出
}