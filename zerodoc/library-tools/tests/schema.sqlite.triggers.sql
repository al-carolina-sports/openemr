-- Fault injection, for the rollback test only. The real migrations have no such
-- trigger. It makes one insert fail late in the import sequence - after the
-- templates, elements and options are already in - so the test can prove the
-- transaction unwinds all of it. There is no way to provoke that from the
-- import format itself, because every defect the format can express is caught
-- by a validator before the transaction opens, which is the intended design.
CREATE TRIGGER zdoc_test_fault_injection
BEFORE INSERT ON zdoc_saved_finding
WHEN NEW.name LIKE '%FAULT INJECTION%'
BEGIN
  SELECT RAISE(ABORT, 'fault injection: simulated database failure');
END;
