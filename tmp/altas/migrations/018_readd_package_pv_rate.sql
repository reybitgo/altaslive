-- Migration 018: Re-add package_pv_rate for binary/direct/indirect PV basis
-- Package PV is used for binary tree placement and commission calculations,
-- but it does NOT flow into Personal PV or Group PV (reserved for products).

ALTER TABLE packages
  ADD COLUMN package_pv_rate DECIMAL(5,2) NOT NULL DEFAULT 100.00
    COMMENT 'Percentage of entry fee that becomes package PV for binary/direct/indirect basis' AFTER entry_fee;
