-- MyÚčto.cz — Kniha jízd: pravidelné cesty a stavy tachometru.
--
--   trip_templates     pravidelné cesty (odkud, kam, účel, km, kategorie, obvyklý čas);
--                      car_id NULL = cesta pro libovolné auto. Z cest se jedním klikem
--                      tvoří jízdy ve formuláři i při doplňování km.
--   odometer_readings  zjištěné stavy tachometru k datu. Rozdíl mezi stavem a koncem
--                      zapsaných jízd ukáže Kniha jízd jako km k doplnění
--                      (OdometerGapCalculator). Sloupec source rozliší ruční zápis
--                      od budoucích automatických zdrojů; jeden stav na auto, den a zdroj.
--
-- Tenantová integrita odkazů (car_id, category_id) se hlídá v aplikaci přes
-- TenantReferenceGuard, stejně jako u trips.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS trip_templates (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id          INT UNSIGNED NOT NULL,
    car_id               INT UNSIGNED NULL COMMENT 'NULL = libovolné auto',

    label                VARCHAR(120) NOT NULL,
    origin               VARCHAR(255) NULL,
    destination          VARCHAR(255) NULL,
    purpose              VARCHAR(255) NULL,
    distance_km          DECIMAL(8,1) NOT NULL DEFAULT 0,
    category_id          SMALLINT UNSIGNED NULL,
    time_start           TIME NULL,
    time_end             TIME NULL,

    display_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_archived          TINYINT(1) NOT NULL DEFAULT 0,
    created_by           BIGINT UNSIGNED NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_trip_templates_supplier (supplier_id, is_archived, display_order),
    KEY idx_trip_templates_car      (car_id),
    KEY idx_trip_templates_category (category_id),

    CONSTRAINT fk_trip_templates_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)        ON DELETE CASCADE,
    CONSTRAINT fk_trip_templates_car      FOREIGN KEY (car_id)      REFERENCES cars(id)            ON DELETE CASCADE,
    CONSTRAINT fk_trip_templates_category FOREIGN KEY (category_id) REFERENCES trip_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_trip_templates_user     FOREIGN KEY (created_by)  REFERENCES users(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS odometer_readings (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id          INT UNSIGNED NOT NULL,
    car_id               INT UNSIGNED NOT NULL,

    reading_date         DATE NOT NULL,
    reading_time         TIME NULL,
    odometer             INT UNSIGNED NOT NULL,
    source               ENUM('manual') NOT NULL DEFAULT 'manual',
    note                 VARCHAR(255) NULL,

    created_by           BIGINT UNSIGNED NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_odometer_readings_day (car_id, reading_date, source),
    KEY idx_odometer_readings_supplier (supplier_id, car_id, reading_date),

    CONSTRAINT fk_odometer_readings_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_odometer_readings_car      FOREIGN KEY (car_id)      REFERENCES cars(id)     ON DELETE CASCADE,
    CONSTRAINT fk_odometer_readings_user     FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
