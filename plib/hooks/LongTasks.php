<?php

class Modules_VirustotalSiteChecker_LongTasks extends pm_Hook_LongTasks
{
    public function getLongTasks()
    {
        return [new Modules_VirustotalSiteChecker_Task_Scan()];
    }
}