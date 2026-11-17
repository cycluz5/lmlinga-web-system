#!/bin/bash
# chain A (local): health-search comparison on both branches, then the full Local AI run
cd "C:/Users/Kathlyn Cris/Desktop/final/lmlinga-web-system"
THREE_MODE=ai THREE_TAG=ragbranch php artisan tinker --execute="require 'C:/Users/Kathlyn Cris/Desktop/final/lmlinga-train/ml/bikol-chat/three_types/run_rag_only.php';" 2>&1 | tail -1
cd "C:/Users/Kathlyn Cris/Desktop/final/lmlinga-train"
THREE_MODE=ai THREE_TAG=trainbranch php artisan tinker --execute="require 'C:/Users/Kathlyn Cris/Desktop/final/lmlinga-train/ml/bikol-chat/three_types/run_rag_only.php';" 2>&1 | tail -1
THREE_MODE=ai php artisan tinker --execute="require 'C:/Users/Kathlyn Cris/Desktop/final/lmlinga-train/ml/bikol-chat/three_types/run_tests.php';" 2>&1 | tail -1
echo "CHAIN A DONE"
