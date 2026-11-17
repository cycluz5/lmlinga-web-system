#!/bin/bash
cd "C:/Users/Kathlyn Cris/Desktop/final/lmlinga-train"
THREE_MODE=cloud php artisan tinker --execute="require 'C:/Users/Kathlyn Cris/Desktop/final/lmlinga-train/ml/bikol-chat/three_types/run_tests.php';" 2>&1 | tail -1
echo "CLOUD DONE"
