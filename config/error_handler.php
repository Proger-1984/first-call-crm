<?php

return [
    'display_error_details' => filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN),
    'log_errors' => true,
    'log_error_details' => true,
]; 