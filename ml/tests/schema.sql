-- The subset of the Laravel schema the jobs read and write (see expected_columns.json), rebuilt for every db-marked test.
drop schema public cascade;
create schema public;
create extension if not exists vector;

create table users (id bigserial primary key, deactivated_at timestamp);
create table profiles (user_id bigint primary key references users (id) on delete cascade, personalization_enabled boolean not null default true);

create table media (
    id bigserial primary key,
    title varchar(255) not null,
    alternative_titles jsonb not null default '[]',
    description text,
    published_on date,
    ratings_count integer not null default 0,
    deleted_at timestamp,
    merged_into_id bigint references media (id) on delete set null
);

create table user_interactions (
    id bigserial primary key,
    user_id bigint not null references users (id) on delete cascade,
    media_id bigint references media (id) on delete set null,
    event varchar(255) not null,
    value real,
    context jsonb not null default '{}',
    occurred_at timestamp not null
);

create table genres (id bigserial primary key, name varchar(255) not null);
create table themes (id bigserial primary key, name varchar(255) not null);
create table tags (id bigserial primary key, name varchar(255) not null);
create table characters (id bigserial primary key, name varchar(255) not null);
create table media_genres (media_id bigint not null references media (id) on delete cascade, genre_id bigint not null references genres (id) on delete cascade, primary key (media_id, genre_id));
create table media_themes (media_id bigint not null references media (id) on delete cascade, theme_id bigint not null references themes (id) on delete cascade, primary key (media_id, theme_id));
create table media_tags (media_id bigint not null references media (id) on delete cascade, tag_id bigint not null references tags (id) on delete cascade, primary key (media_id, tag_id));
create table media_characters (media_id bigint not null references media (id) on delete cascade, character_id bigint not null references characters (id) on delete cascade, primary key (media_id, character_id));

create table model_versions (
    id bigserial primary key,
    name varchar(255) not null,
    version varchar(255) not null,
    kind varchar(255) not null,
    status varchar(255) not null,
    trained_at timestamp,
    dataset_ref text,
    parameters jsonb not null default '{}',
    metrics jsonb not null default '{}',
    artifact_uri varchar(255),
    created_at timestamp,
    updated_at timestamp,
    unique (name, version)
);
create unique index model_versions_one_active on model_versions (name) where status = 'active';

create table media_features (
    media_id bigint not null references media (id) on delete cascade,
    model_version_id bigint not null references model_versions (id) on delete cascade,
    top_terms jsonb not null default '[]',
    summary text,
    computed_at timestamp not null,
    primary key (media_id, model_version_id)
);

create table media_lsa_vectors (
    media_id bigint not null references media (id) on delete cascade,
    model_version_id bigint not null references model_versions (id) on delete cascade,
    vector vector(200) not null,
    primary key (media_id, model_version_id)
);
create index media_lsa_vectors_vector_idx on media_lsa_vectors using hnsw (vector vector_cosine_ops);

create table media_similarities (
    media_id bigint not null references media (id) on delete cascade,
    similar_media_id bigint not null references media (id) on delete cascade,
    method varchar(255) not null,
    score real not null,
    model_version_id bigint not null references model_versions (id) on delete cascade,
    primary key (media_id, method, similar_media_id),
    check (media_id <> similar_media_id)
);
