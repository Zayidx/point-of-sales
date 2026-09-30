<?php

return [
    'high_waste_cost_threshold' => (int) env('OPERATIONS_HIGH_WASTE_COST_THRESHOLD', 50000),
    'production_output_variance_threshold' => (int) env('OPERATIONS_PRODUCTION_OUTPUT_VARIANCE_THRESHOLD', 5),
    'outlet_close_grace_minutes' => (int) env('OPERATIONS_OUTLET_CLOSE_GRACE_MINUTES', 15),
    'outlet_close_notification_window_minutes' => (int) env('OPERATIONS_OUTLET_CLOSE_NOTIFICATION_WINDOW_MINUTES', 120),
];
