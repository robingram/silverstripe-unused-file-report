<?php
if (!class_exists("AbstractQueuedJob")) {
    return;
}

/**
 * Allows the optional use of queued jobs module to to run the unused file
 * report builder task. If the module isn't installed, nothing is
 * done - SilverStripe will never include this class declaration.
 *
 * @see https://github.com/symbiote/silverstripe-queuedjobs
 * @author Rob Ingram <robert.ingram@ccc.govt.nz>
 * @package Unused File Report
 */
class UnusedFileReportJob extends AbstractQueuedJob implements QueuedJob
{
    /**
     * @return string
     */
    public function getTitle()
    {
        return "Unused File Report Builder Task";
    }

    /**
     * {@inheritDoc}
     * @return string
     */
    public function getJobType()
    {
        $this->totalSteps = 1;
        echo $this->totalSteps;
        return QueuedJob::QUEUED;
    }

    /**
     * {@inheritDoc}
     */
    public function process()
    {
        $task = new UnusedFileReportBuildTask();
        $task->run(new SS_HTTPRequest('GET', "/dev/tasks/UnusedFileReportBuildTask"));

        $this->currentStep = 1;
        $this->isComplete = true;
        echo $this->isComplete;
    }
}
