<?php

arch('application code contains no debugging or output statements')
    ->preset()
    ->php();

arch('application code avoids insecure functions')
    ->preset()
    ->security();

arch('environment variables are read only in configuration files')
    ->expect('env')
    ->not->toBeUsedIn('App');
