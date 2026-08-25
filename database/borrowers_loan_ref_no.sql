-- Manual, staff-entered client/loan reference number (format "SDLOAN000..."),
-- distinct from the system-generated borrower_no (BRW-...). Assigned once per
-- borrower at first registration and reused on every subsequent loan for that
-- same person -- this is the exact identifier Collexia's payment processing
-- system recognizes for the client, so it's sent as the mandate's clientNo
-- instead of the internally-derived value whenever it's on file.
--
-- Nullable + UNIQUE: MySQL allows any number of NULLs in a unique index, so
-- existing borrowers without one yet are unaffected, while no two borrowers
-- can ever be assigned the same reference by mistake.

ALTER TABLE borrowers
    ADD COLUMN loan_ref_no VARCHAR(30) NULL AFTER borrower_no,
    ADD UNIQUE KEY unique_borrowers_loan_ref_no (loan_ref_no);
