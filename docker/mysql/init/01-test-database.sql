-- ---------------------------------------------------------------
-- PHPUnit runs against a real MySQL schema (the ORM and the money
-- maths are only meaningfully tested against the real engine), so
-- provision the test database alongside the app database.
-- ---------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS `vendorservice_test`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `vendorservice_test`.* TO 'vendorservice'@'%';

FLUSH PRIVILEGES;
