-- Datenbank poc_db: aufbereitetes GFF-Gutachten „Ist die AfD verfassungswidrig?“ (CC BY 4.0)
-- Wird von import.php vollständig neu angelegt.

DROP TABLE IF EXISTS afd_verweis;
DROP TABLE IF EXISTS afd_absatz;
DROP TABLE IF EXISTS afd_fussnote;
DROP TABLE IF EXISTS afd_abschnitt;
DROP TABLE IF EXISTS afd_meta;

CREATE TABLE afd_abschnitt (
    id              INT UNSIGNED      NOT NULL PRIMARY KEY,
    eltern          INT UNSIGNED      NULL,
    rang            TINYINT UNSIGNED  NOT NULL COMMENT '0 Teil, 1 A., 2 I., 3 1., 4 a), 5 aa), 6 (1), 7 (a), 8 (aa)',
    nummer          VARCHAR(160)      NOT NULL COMMENT 'Gliederungsnummer wie im Gutachten, z. B. Teil 3 A.III.2.c)cc)(1)',
    titel           VARCHAR(600)      NOT NULL,
    pfad            TEXT              NOT NULL COMMENT 'übergeordnete Abschnitte, getrennt durch " › "',
    seite_von       SMALLINT UNSIGNED NULL,
    seite_bis       SMALLINT UNSIGNED NULL,
    pdf_von         SMALLINT UNSIGNED NOT NULL,
    pdf_bis         SMALLINT UNSIGNED NOT NULL,
    absaetze        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    zeichen         INT UNSIGNED      NOT NULL DEFAULT 0,
    unterabschnitte SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    titel_fn        VARCHAR(100)      NULL COMMENT 'Fußnoten an der Überschrift, kommagetrennt',
    KEY ix_eltern (eltern),
    KEY ix_nummer (nummer),
    FULLTEXT KEY ft_titel (titel, nummer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE afd_absatz (
    id           INT UNSIGNED      NOT NULL PRIMARY KEY,
    abschnitt_id INT UNSIGNED      NOT NULL,
    nr           SMALLINT UNSIGNED NOT NULL,
    typ          ENUM('text','zitat','liste') NOT NULL,
    seite        SMALLINT UNSIGNED NULL,
    seite_bis    SMALLINT UNSIGNED NULL,
    pdf          SMALLINT UNSIGNED NOT NULL,
    pdf_bis      SMALLINT UNSIGNED NOT NULL,
    text         MEDIUMTEXT        NOT NULL COMMENT 'Fußnotenverweise als [^n]',
    KEY ix_abschnitt (abschnitt_id, nr),
    KEY ix_pdf (pdf, pdf_bis),
    FULLTEXT KEY ft_text (text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE afd_fussnote (
    nr        SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    seite     SMALLINT UNSIGNED NULL,
    pdf       SMALLINT UNSIGNED NOT NULL,
    text      TEXT              NOT NULL,
    urls      JSON              NULL,
    datenbank VARCHAR(300)      NULL COMMENT 'Beleg in der AfD-Datenbank von FragDenStaat',
    KEY ix_pdf (pdf),
    FULLTEXT KEY ft_text (text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE afd_verweis (
    nr           SMALLINT UNSIGNED NOT NULL COMMENT 'Fußnote',
    absatz_id    INT UNSIGNED      NULL,
    abschnitt_id INT UNSIGNED      NOT NULL,
    KEY ix_nr (nr),
    KEY ix_absatz (absatz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE afd_meta (
    schluessel VARCHAR(60) NOT NULL PRIMARY KEY,
    wert       TEXT        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
