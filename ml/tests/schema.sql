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

create table media_embeddings (
    media_id bigint not null references media (id) on delete cascade,
    model_version_id bigint not null references model_versions (id) on delete cascade,
    embedding vector(384) not null,
    content_hash varchar(64) not null,
    primary key (media_id, model_version_id)
);
create index media_embeddings_embedding_idx on media_embeddings using hnsw (embedding vector_cosine_ops);

create table media_summaries (
    media_id bigint primary key references media (id) on delete cascade,
    summary text not null,
    source_hash varchar(64) not null,
    method varchar(32) not null,
    generated_at timestamp not null
);

create table media_similarities (
    media_id bigint not null references media (id) on delete cascade,
    similar_media_id bigint not null references media (id) on delete cascade,
    method varchar(255) not null,
    score real not null,
    model_version_id bigint not null references model_versions (id) on delete cascade,
    primary key (media_id, method, model_version_id, similar_media_id),
    check (media_id <> similar_media_id)
);

-- The view Laravel's migrations build, for the refresh job (same definition as database/migrations/2026_11_05_000004_*).
create materialized view media_popularity as
with counts as (
    select
        ui.media_id,
        count(*) filter (where ui.occurred_at >= now() - interval '7 days') as interactions_7d,
        count(*) as interactions_30d
    from user_interactions ui
    join users u on u.id = ui.user_id and u.deactivated_at is null
    left join profiles p on p.user_id = u.id
    where ui.media_id is not null
        and ui.occurred_at >= now() - interval '30 days'
        and coalesce(p.personalization_enabled, true)
    group by ui.media_id
),
totals as (
    select
        m.id as media_id,
        coalesce(c.interactions_7d, 0)::integer as interactions_7d,
        coalesce(c.interactions_30d, 0)::integer as interactions_30d,
        coalesce(c.interactions_30d, 0) + m.ratings_count as engagement
    from media m
    left join counts c on c.media_id = m.id
    where m.deleted_at is null and m.merged_into_id is null
),
scored as (
    select
        media_id,
        interactions_7d,
        interactions_30d,
        engagement,
        (interactions_7d + 0.25 * (interactions_30d - interactions_7d))::double precision as trending_score
    from totals
),
active as (
    select media_id, (cume_dist() over (order by trending_score))::double precision as trending_percentile
    from scored
    where interactions_30d > 0
)
select
    s.media_id,
    s.interactions_7d,
    s.interactions_30d,
    s.trending_score,
    (percent_rank() over (order by s.engagement))::double precision as popularity_percentile,
    coalesce(a.trending_percentile, 0)::double precision as trending_percentile
from scored s
left join active a on a.media_id = s.media_id;
create unique index media_popularity_media_id on media_popularity (media_id);
