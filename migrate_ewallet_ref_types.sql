-- ============================================================
--  E-WALLET LEDGER ref_type — add the missing 'upgrade' value
--  (tmp/shop/remedy.md Phase 2, defect C7)
-- ============================================================
--
--  THE BUG
--    models/Ewallet.php allow-lists 'upgrade', and models/User.php:434 uses
--    it when crediting the admin for an e-wallet-funded package upgrade:
--
--        Ewallet::credit($adminId, $diff, $userId, 'upgrade', …);
--
--    But the column never accepted it:
--
--        ewallet_ledger.ref_type = enum('commission','payout','reactivation',
--                                     'transfer','topup','registration',
--                                     'shop_order')
--
--    MySQL 8 strict mode therefore rejects the insert:
--
--        ERROR 1265 (01000): Data truncated for column 'ref_type' at row 1
--
--    Ewallet::debitInternal() runs first and has no allow-list, so it succeeds;
--    the credit at User.php:434 then throws, and because both sit inside the
--    upgrade transaction the WHOLE upgrade rolls back. Net effect: an
--    e-wallet package upgrade cannot complete.
--
--    This is the mirror image of C2 ('shop_order' is in the column but not in
--    the PHP allow-list). Both directions of that drift are now covered by
--    tests/SchemaDriftTest.php.
--
--  THE FIX
--    Add 'upgrade' to the column. The credit itself is legitimate and distinct
--    from 'registration', so the value stays.
--
--  NOTES
--    * Safe to run repeatedly; the information_schema guard makes it a no-op
--      once the value is present.
--    * ALTER only — no table rewrite, no data movement.
--    * Run BEFORE deploying the matching Ewallet.php change so the PHP
--      allow-list can never reference a value the column lacks.
-- ============================================================

SET @has_upgrade = (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'ewallet_ledger'
       AND COLUMN_NAME  = 'ref_type'
       AND COLUMN_TYPE  LIKE '%''upgrade''%'
);

SET @sql = IF(
    @has_upgrade > 0,
    'DO 0',
    'ALTER TABLE ewallet_ledger
       MODIFY COLUMN ref_type
         ENUM(''commission'',''payout'',''reactivation'',''transfer'',''topup'',
              ''registration'',''upgrade'',''shop_order'')
         NULL
       COMMENT ''Origin of the ledger row. Keep in sync with Ewallet::VALID_REF_TYPES
                (tests/SchemaDriftTest.php asserts they match).'''
);

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Report the state the migration finished in.
SELECT COLUMN_TYPE AS ref_type_after_migration
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 'ewallet_ledger'
   AND COLUMN_NAME  = 'ref_type';