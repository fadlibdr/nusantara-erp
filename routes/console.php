<?php

// Module console commands are registered inside each module's service provider.
// Scheduled tasks (e.g. ServiceDesk preventive-maintenance generation) are also
// defined there via $this->callAfterResolving(Schedule::class, ...).
