#!/bin/bash

echo "============================================"
echo " Workforce Compliance Platform - Test Runner"
echo "============================================"

if [ -f /var/www/artisan ]; then
    cd /var/www
fi

if [ ! -d "vendor" ]; then
    echo "Installing dependencies..."
    composer install --no-interaction || { echo "Composer install failed"; exit 1; }
fi

php artisan config:clear 2>/dev/null || true

if [ -f .env ] && [ -f .env.testing ]; then
    cp .env .env.backup.run_tests
    cp .env.testing .env
fi

FINAL_EXIT=0

echo ""
echo "============================================"
echo " [1/3] Running unit_tests/"
echo "       (core logic, state transitions,"
echo "        boundary conditions)"
echo "============================================"
echo ""

DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit --testsuite UnitTests --no-coverage
UNIT_EXIT=$?

if [ $UNIT_EXIT -eq 0 ]; then
    echo ""
    echo " >>> unit_tests/ : PASSED"
else
    echo ""
    echo " >>> unit_tests/ : FAILED"
    FINAL_EXIT=1
fi

echo ""
echo "============================================"
echo " [2/3] Running API_tests/"
echo "       (normal inputs, missing params,"
echo "        permission errors, IDOR)"
echo "============================================"
echo ""

DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit --testsuite ApiTests --no-coverage
API_EXIT=$?

if [ $API_EXIT -eq 0 ]; then
    echo ""
    echo " >>> API_tests/  : PASSED"
else
    echo ""
    echo " >>> API_tests/  : FAILED"
    FINAL_EXIT=1
fi

echo ""
echo "============================================"
echo " [3/3] Running tests/ with coverage"
echo "       (Unit + Feature)"
echo "============================================"
echo ""

DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit --testsuite Unit,Feature --coverage-text
CORE_EXIT=$?

if [ $CORE_EXIT -ne 0 ]; then
    FINAL_EXIT=1
fi

echo ""
echo "============================================"
echo "              SUMMARY"
echo "============================================"
echo ""
echo "  unit_tests/  : $([ $UNIT_EXIT -eq 0 ] && echo 'PASSED' || echo 'FAILED')"
echo "  API_tests/   : $([ $API_EXIT -eq 0 ] && echo 'PASSED' || echo 'FAILED')"
echo "  tests/ (cov) : $([ $CORE_EXIT -eq 0 ] && echo 'PASSED' || echo 'FAILED')"
echo ""

if [ $FINAL_EXIT -eq 0 ]; then
    echo "  ALL TESTS PASSED"
else
    echo "  SOME TESTS FAILED"
fi

echo "============================================"

if [ -f .env.backup.run_tests ]; then
    mv .env.backup.run_tests .env
fi

exit $FINAL_EXIT
