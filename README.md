# Unused File Report

This module adds an "Unused Files" report to the CMS. Because generating the list
of potentially unused files could take a long time on even a reasonably small site
the report does not work from live data. Instead, a task has been created that will
populate a table in the DB with the list of files that may not be in use.

In addition, if the [Queued Jobs](https://github.com/symbiote/silverstripe-queuedjobs)
module is installed a job will be created that will allow the task to be scheduled
at a convenient time.

## Versions

This version requires Silverstripe 5.

For Silverstripe 3 use the 1.x releases.
For Silverstripe 4 use the 2.x releases.

## Installation

```
composer require robingram/silverstripe-unused-file-report
```

## Running the task

### Through a browser

The report builder task will appear under `/dev/tasks/` as "Build table for
Unused File Reports". Click on the task title to initiate it.

### From the command line

The task can be run from the command line using `sake`:

```sake dev/tasks/UnusedFileReportBuildTask```

## Viewing the report

The report will appear under the Reports tab as "Unused Files Report". If the
builder task has not been run the report will contain no data.

## Running/scheduling the job

If the Queued Jobs module is installed then the report builder job will appear
in the "Create job of type" drop-down list in the Jobs tab as `UnusedFileReportJob`.

## Credits

Thanks to Christchurch City Council, New Zealand, for enabling the development
of this module.
