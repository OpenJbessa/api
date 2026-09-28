-- Base dédiée aux tests : RefreshDatabase la vide à chaque exécution, elle ne
-- doit donc jamais être celle du développement.
CREATE DATABASE app_test OWNER app;
