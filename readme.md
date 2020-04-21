## Version Prune

This module adds a task you can run at will which does the following:

 - Clears all version history after the first 5 for each non-archived record
 - Clears all version history for archived records
 - Clears all version history for orphaned records

This automatically triggers on all dataobjects that have the versioned
extension appled to the base class.

Note: Running this task means that archived records can no longer be
recovered! Make a database backup if this is not your intention.

For example, if you run this nightly, you can work with records during the day,
including recovering deleted records, but not if you leave it overnight.

## Installation

```bash
composer require isobar-nz/silverstripe-versionprune
```
