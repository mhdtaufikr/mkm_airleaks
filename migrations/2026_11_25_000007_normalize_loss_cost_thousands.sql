USE `mkm_airleak`;

-- Nilai cost yang pernah diinput sebagai singkatan ribuan (contoh: 20 = Rp20.000)
-- dinormalisasi sekali. Kondisi < 1000 membuat migration aman dijalankan ulang.
UPDATE `air_leak_audits`
SET `loss_cost` = `loss_cost` * 1000
WHERE `loss_cost` >= 1
  AND `loss_cost` < 1000;
