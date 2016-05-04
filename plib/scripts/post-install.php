<?php

pm_Context::init('virustotal-site-checker');

pm_Settings::set('_promo_admin_home', 1);

$tasks = pm_Scheduler::getInstance()->listTasks();
foreach ($tasks as $task) {
    if ('virustotal-periodic-task.php' == $task->getCmd()) {
        pm_Settings::set('virustotal_periodic_task_id', $task->getId());
        return;
    }
}
$task = new pm_Scheduler_Task();
$task->setSchedule(pm_Scheduler::$EVERY_DAY);
$task->setCmd('virustotal-periodic-task.php');
pm_Scheduler::getInstance()->putTask($task);
pm_Settings::set('virustotal_periodic_task_id', $task->getId());

