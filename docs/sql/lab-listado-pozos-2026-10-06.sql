-- Cliente de laboratorio, solo PostgreSQL.
-- No ejecutar contra MySQL ni contra la base de Laravel.
-- No crea roles ni escribe contraseñas. El rol undersurf_ro tiene que existir antes.
-- El listado lee demo_drill_holes (id, code, status). La tabla de avances queda
-- creada porque el conector la usa después; este rol no recibe INSERT.

CREATE TABLE IF NOT EXISTS demo_drill_holes (
    id     text PRIMARY KEY,
    code   text NOT NULL,
    status text NOT NULL
);

CREATE TABLE IF NOT EXISTS demo_drill_hole_progress (
    id              text PRIMARY KEY,
    hole_id         text NOT NULL REFERENCES demo_drill_holes(id),
    actor           text NOT NULL,
    depth_from      numeric NOT NULL,
    depth_to        numeric NOT NULL,
    observations    text,
    idempotency_key text NOT NULL UNIQUE,
    logged_at       timestamptz NOT NULL DEFAULT now()
);

-- 1004 is the laboratory aborted hole. Only status is stored. Depths and
-- the abandonment reason are not columns of this table.
INSERT INTO demo_drill_holes (id, code, status) VALUES
    ('1001', 'RLEK-01', 'in_progress'),
    ('1002', 'RLEK-02', 'planned'),
    ('1003', 'RLEK-03', 'completed'),
    ('1004', 'RLEK-04', 'aborted')
ON CONFLICT (id) DO NOTHING;

GRANT CONNECT ON DATABASE undersurf_client_lab TO undersurf_ro;
GRANT USAGE ON SCHEMA public TO undersurf_ro;
GRANT SELECT ON TABLE demo_drill_holes TO undersurf_ro;
