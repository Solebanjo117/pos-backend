--
-- PostgreSQL database dump
--

\restrict JFK3j8u1FfMg7LtteAAHclcJqsehqvbB4Wp2aPuDInV3dPZ136w86QfyofSkXpu

-- Dumped from database version 18.1
-- Dumped by pg_dump version 18.1

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: activity_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.activity_log (
    id bigint NOT NULL,
    log_name character varying(255),
    description text NOT NULL,
    subject_type character varying(255),
    subject_id bigint,
    causer_type character varying(255),
    causer_id bigint,
    properties json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    event character varying(255),
    batch_uuid uuid
);


--
-- Name: activity_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.activity_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: activity_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.activity_log_id_seq OWNED BY public.activity_log.id;


--
-- Name: alternative_products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.alternative_products (
    id bigint NOT NULL,
    primary_product_id bigint NOT NULL,
    alternative_product_id bigint NOT NULL,
    relationship_type character varying(255) DEFAULT 'compatible'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT alternative_products_relationship_type_check CHECK (((relationship_type)::text = ANY ((ARRAY['compatible'::character varying, 'substitute'::character varying, 'upgrade'::character varying])::text[])))
);


--
-- Name: alternative_products_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.alternative_products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: alternative_products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.alternative_products_id_seq OWNED BY public.alternative_products.id;


--
-- Name: attribute_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.attribute_definitions (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    data_type character varying(255) DEFAULT 'string'::character varying NOT NULL,
    unit character varying(20),
    applicable_categories json,
    is_required boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT attribute_definitions_data_type_check CHECK (((data_type)::text = ANY ((ARRAY['decimal'::character varying, 'integer'::character varying, 'string'::character varying, 'boolean'::character varying])::text[])))
);


--
-- Name: COLUMN attribute_definitions.unit; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.attribute_definitions.unit IS 'V, A, mm, kg, etc.';


--
-- Name: COLUMN attribute_definitions.applicable_categories; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.attribute_definitions.applicable_categories IS 'Array of category IDs';


--
-- Name: attribute_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.attribute_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: attribute_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.attribute_definitions_id_seq OWNED BY public.attribute_definitions.id;


--
-- Name: brand_sequences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.brand_sequences (
    id bigint NOT NULL,
    brand_id bigint NOT NULL,
    next_value bigint DEFAULT '1'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: brand_sequences_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.brand_sequences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: brand_sequences_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.brand_sequences_id_seq OWNED BY public.brand_sequences.id;


--
-- Name: brand_supplier; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.brand_supplier (
    id bigint NOT NULL,
    brand_id bigint NOT NULL,
    supplier_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: brand_supplier_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.brand_supplier_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: brand_supplier_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.brand_supplier_id_seq OWNED BY public.brand_supplier.id;


--
-- Name: brands; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.brands (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    description text,
    logo_url character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    website character varying(255),
    prefix character varying(5),
    CONSTRAINT brands_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying])::text[])))
);


--
-- Name: brands_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.brands_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: brands_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.brands_id_seq OWNED BY public.brands.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cash_movements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cash_movements (
    id bigint NOT NULL,
    pos_session_id bigint NOT NULL,
    user_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    amount numeric(10,2) NOT NULL,
    reason character varying(255) NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT cash_movements_type_check CHECK (((type)::text = ANY ((ARRAY['in'::character varying, 'out'::character varying])::text[])))
);


--
-- Name: cash_movements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.cash_movements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: cash_movements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.cash_movements_id_seq OWNED BY public.cash_movements.id;


--
-- Name: categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.categories (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    slug character varying(100) NOT NULL,
    parent_id bigint,
    description text,
    icon character varying(50),
    priority integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    default_attributes json,
    pricing_formula text,
    default_margin_percent numeric(5,2),
    applies_itbm boolean DEFAULT false NOT NULL,
    itbm_percent numeric(5,2) DEFAULT '7'::numeric NOT NULL,
    default_cost numeric(12,2),
    min_suggested_price numeric(12,2)
);


--
-- Name: COLUMN categories.icon; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.categories.icon IS 'Icon name for UI';


--
-- Name: COLUMN categories.priority; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.categories.priority IS 'Display order';


--
-- Name: COLUMN categories.default_attributes; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.categories.default_attributes IS 'Default attribute specs for products in this category';


--
-- Name: categories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.categories_id_seq OWNED BY public.categories.id;


--
-- Name: customer_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_transactions (
    id bigint NOT NULL,
    customer_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    reference_type character varying(100),
    reference_id bigint,
    amount numeric(12,2) NOT NULL,
    balance_before numeric(12,2) NOT NULL,
    balance_after numeric(12,2) NOT NULL,
    description text,
    transaction_date timestamp(0) without time zone NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT customer_transactions_type_check CHECK (((type)::text = ANY ((ARRAY['sale'::character varying, 'payment'::character varying, 'credit_note'::character varying, 'adjustment'::character varying])::text[])))
);


--
-- Name: customer_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.customer_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: customer_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.customer_transactions_id_seq OWNED BY public.customer_transactions.id;


--
-- Name: customers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customers (
    id bigint NOT NULL,
    code character varying(50) NOT NULL,
    type character varying(255) DEFAULT 'individual'::character varying NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255),
    phone character varying(50),
    tax_id character varying(100),
    tax_name character varying(255),
    address text,
    city character varying(100),
    state character varying(100),
    country character varying(100) DEFAULT 'México'::character varying NOT NULL,
    postal_code character varying(20),
    credit_limit numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    credit_days integer DEFAULT 0 NOT NULL,
    current_balance numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    contact_person character varying(255),
    contact_phone character varying(50),
    notes text,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT customers_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying, 'blocked'::character varying])::text[]))),
    CONSTRAINT customers_type_check CHECK (((type)::text = ANY ((ARRAY['individual'::character varying, 'business'::character varying])::text[])))
);


--
-- Name: customers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.customers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: customers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.customers_id_seq OWNED BY public.customers.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: inventory; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    warehouse_id bigint NOT NULL,
    stock_available integer DEFAULT 0 NOT NULL,
    stock_reserved integer DEFAULT 0 NOT NULL,
    stock_on_order integer DEFAULT 0 NOT NULL,
    reorder_point integer DEFAULT 0 NOT NULL,
    target_level integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: COLUMN inventory.stock_reserved; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory.stock_reserved IS 'Reserved for pending orders';


--
-- Name: COLUMN inventory.stock_on_order; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory.stock_on_order IS 'On purchase orders';


--
-- Name: COLUMN inventory.target_level; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.inventory.target_level IS 'Desired inventory level';


--
-- Name: inventory_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.inventory_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: inventory_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.inventory_id_seq OWNED BY public.inventory.id;


--
-- Name: inventory_movements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_movements (
    id bigint NOT NULL,
    movement_type character varying(255) NOT NULL,
    product_id bigint NOT NULL,
    warehouse_id bigint NOT NULL,
    destination_warehouse_id bigint,
    quantity integer NOT NULL,
    reference_type character varying(50),
    reference_id bigint,
    notes text,
    user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    product_batch_id bigint,
    variant_id bigint,
    reason character varying(255),
    supplier_id bigint,
    CONSTRAINT inventory_movements_movement_type_check CHECK (((movement_type)::text = ANY ((ARRAY['in'::character varying, 'out'::character varying, 'transfer'::character varying])::text[])))
);


--
-- Name: inventory_movements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.inventory_movements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: inventory_movements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.inventory_movements_id_seq OWNED BY public.inventory_movements.id;


--
-- Name: inventory_transfer_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_transfer_items (
    id bigint NOT NULL,
    inventory_transfer_id bigint NOT NULL,
    product_id bigint NOT NULL,
    variant_id bigint,
    quantity integer NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: inventory_transfer_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.inventory_transfer_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: inventory_transfer_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.inventory_transfer_items_id_seq OWNED BY public.inventory_transfer_items.id;


--
-- Name: inventory_transfers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_transfers (
    id bigint NOT NULL,
    reference character varying(255) NOT NULL,
    from_warehouse_id bigint NOT NULL,
    to_warehouse_id bigint NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    created_by bigint NOT NULL,
    received_by bigint,
    notes text,
    dispatched_at timestamp(0) without time zone,
    received_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT inventory_transfers_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'in_transit'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: inventory_transfers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.inventory_transfers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: inventory_transfers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.inventory_transfers_id_seq OWNED BY public.inventory_transfers.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: oem_references; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oem_references (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    oem_code character varying(50) NOT NULL,
    manufacturer character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: oem_references_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.oem_references_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: oem_references_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.oem_references_id_seq OWNED BY public.oem_references.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: payment_methods; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.payment_methods (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    code character varying(255) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    is_system boolean DEFAULT false NOT NULL,
    requires_reference boolean DEFAULT false NOT NULL,
    requires_cash_count boolean DEFAULT false NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: payment_methods_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.payment_methods_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: payment_methods_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.payment_methods_id_seq OWNED BY public.payment_methods.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: pos_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pos_sessions (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    opened_at timestamp(0) without time zone,
    closed_at timestamp(0) without time zone,
    opening_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    closing_amount numeric(10,2),
    expected_amount numeric(10,2),
    status character varying(255) DEFAULT 'open'::character varying NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    closing_details json,
    warehouse_id bigint
);


--
-- Name: pos_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pos_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pos_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pos_sessions_id_seq OWNED BY public.pos_sessions.id;


--
-- Name: pricing_formulas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pricing_formulas (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    formula character varying(255) NOT NULL,
    description text,
    is_preset boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    sort_order smallint DEFAULT '0'::smallint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pricing_formulas_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pricing_formulas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pricing_formulas_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pricing_formulas_id_seq OWNED BY public.pricing_formulas.id;


--
-- Name: product_attributes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_attributes (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    value character varying(255) NOT NULL,
    unit character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    variant_id bigint
);


--
-- Name: product_attributes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_attributes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_attributes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_attributes_id_seq OWNED BY public.product_attributes.id;


--
-- Name: product_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_batches (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    batch_number character varying(255) NOT NULL,
    manufacturing_date date,
    expiry_date date,
    cost numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    supplier_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    notes text
);


--
-- Name: product_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_batches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_batches_id_seq OWNED BY public.product_batches.id;


--
-- Name: product_extra_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_extra_categories (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    category_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: product_extra_categories_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_extra_categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_extra_categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_extra_categories_id_seq OWNED BY public.product_extra_categories.id;


--
-- Name: product_inventory; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_inventory (
    id bigint NOT NULL,
    product_id bigint,
    warehouse_id bigint NOT NULL,
    quantity_available integer DEFAULT 0 NOT NULL,
    quantity_reserved integer DEFAULT 0 NOT NULL,
    reorder_point integer DEFAULT 10 NOT NULL,
    reorder_quantity integer DEFAULT 50 NOT NULL,
    location_in_warehouse character varying(255),
    last_restocked_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    variant_id bigint,
    CONSTRAINT stock_non_negative CHECK ((quantity_available >= 0))
);


--
-- Name: product_inventory_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_inventory_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_inventory_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_inventory_id_seq OWNED BY public.product_inventory.id;


--
-- Name: product_lines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_lines (
    id bigint NOT NULL,
    brand_id bigint NOT NULL,
    name character varying(100) NOT NULL,
    description text,
    priority integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: COLUMN product_lines.priority; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.product_lines.priority IS 'Display order';


--
-- Name: product_lines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_lines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_lines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_lines_id_seq OWNED BY public.product_lines.id;


--
-- Name: product_media; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_media (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    media_type character varying(255) DEFAULT 'image'::character varying NOT NULL,
    category character varying(255) DEFAULT 'photo'::character varying NOT NULL,
    file_path character varying(255) NOT NULL,
    original_filename character varying(255) NOT NULL,
    file_size bigint DEFAULT '0'::bigint NOT NULL,
    display_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT product_media_category_check CHECK (((category)::text = ANY ((ARRAY['technical_sheet'::character varying, 'diagram'::character varying, 'manual'::character varying, 'certificate'::character varying, 'photo'::character varying])::text[]))),
    CONSTRAINT product_media_media_type_check CHECK (((media_type)::text = ANY ((ARRAY['image'::character varying, 'pdf'::character varying, 'video'::character varying])::text[])))
);


--
-- Name: COLUMN product_media.file_size; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.product_media.file_size IS 'File size in bytes';


--
-- Name: product_media_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_media_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_media_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_media_id_seq OWNED BY public.product_media.id;


--
-- Name: product_pricing; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_pricing (
    id bigint NOT NULL,
    product_id bigint,
    base_price numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    msrp numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    cost numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    margin_percentage numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    tax_rate numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    max_discount_percentage numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    variant_id bigint,
    tax_type character varying(20) DEFAULT 'standard'::character varying NOT NULL,
    pricing_formula character varying(255),
    cost_tax_rate numeric(5,2),
    sale_tax_rate numeric(5,2),
    cost_base numeric(10,2),
    cost_miscellaneous numeric(10,2) DEFAULT '0'::numeric NOT NULL
);


--
-- Name: COLUMN product_pricing.msrp; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.product_pricing.msrp IS 'Manufacturer Suggested Retail Price';


--
-- Name: COLUMN product_pricing.margin_percentage; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.product_pricing.margin_percentage IS 'Calculated margin %';


--
-- Name: COLUMN product_pricing.tax_rate; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.product_pricing.tax_rate IS 'Tax percentage';


--
-- Name: COLUMN product_pricing.max_discount_percentage; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.product_pricing.max_discount_percentage IS 'Maximum allowed discount';


--
-- Name: COLUMN product_pricing.tax_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.product_pricing.tax_type IS 'standard=7%, exempt=0%, custom=user-defined';


--
-- Name: product_pricing_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_pricing_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_pricing_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_pricing_id_seq OWNED BY public.product_pricing.id;


--
-- Name: product_suppliers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_suppliers (
    id bigint NOT NULL,
    product_id bigint,
    supplier_id bigint NOT NULL,
    supplier_cost numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    lead_time_days integer DEFAULT 0 NOT NULL,
    minimum_order_quantity integer DEFAULT 1 NOT NULL,
    is_preferred boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    variant_id bigint
);


--
-- Name: COLUMN product_suppliers.lead_time_days; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.product_suppliers.lead_time_days IS 'Delivery time in days';


--
-- Name: product_suppliers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_suppliers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_suppliers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_suppliers_id_seq OWNED BY public.product_suppliers.id;


--
-- Name: product_variants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_variants (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    brand_id bigint NOT NULL,
    sku character varying(255) NOT NULL,
    upc character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    system_id character varying(20)
);


--
-- Name: product_variants_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_variants_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_variants_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_variants_id_seq OWNED BY public.product_variants.id;


--
-- Name: product_vehicle_compatibility; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_vehicle_compatibility (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    engine_id bigint,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    chassis_id bigint,
    all_engines boolean DEFAULT false NOT NULL,
    all_chassis boolean DEFAULT false NOT NULL
);


--
-- Name: product_vehicle_compatibility_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_vehicle_compatibility_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_vehicle_compatibility_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_vehicle_compatibility_id_seq OWNED BY public.product_vehicle_compatibility.id;


--
-- Name: product_vins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_vins (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    vin character varying(40) NOT NULL,
    notes character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: product_vins_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_vins_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_vins_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_vins_id_seq OWNED BY public.product_vins.id;


--
-- Name: products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.products (
    id bigint NOT NULL,
    sku_base character varying(50) CONSTRAINT products_sku_not_null NOT NULL,
    product_line_id bigint,
    name character varying(255) NOT NULL,
    description text,
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    batch_tracking boolean DEFAULT false NOT NULL,
    category_id bigint,
    image_url character varying(500),
    is_universal boolean DEFAULT false NOT NULL,
    alternate_code character varying(100),
    pricing_formula character varying(255),
    engine_id bigint,
    system_id character varying(10) NOT NULL,
    CONSTRAINT products_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'discontinued'::character varying, 'pending'::character varying])::text[])))
);


--
-- Name: COLUMN products.image_url; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.products.image_url IS 'Product photo URL';


--
-- Name: products_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.products_id_seq OWNED BY public.products.id;


--
-- Name: purchase_order_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_order_items (
    id bigint NOT NULL,
    purchase_order_id bigint NOT NULL,
    product_id bigint NOT NULL,
    quantity_ordered integer NOT NULL,
    quantity_received integer DEFAULT 0 NOT NULL,
    unit_cost numeric(12,2) NOT NULL,
    subtotal numeric(12,2) NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    variant_id bigint,
    supplier_id bigint,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    units_per_package integer DEFAULT 1 NOT NULL,
    pre_stock_applied_qty integer DEFAULT 0 NOT NULL
);


--
-- Name: COLUMN purchase_order_items.units_per_package; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.purchase_order_items.units_per_package IS 'How many individual units are in each ordered package/box';


--
-- Name: purchase_order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.purchase_order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: purchase_order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.purchase_order_items_id_seq OWNED BY public.purchase_order_items.id;


--
-- Name: purchase_order_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_order_payments (
    id bigint NOT NULL,
    purchase_order_id bigint NOT NULL,
    user_id bigint,
    amount numeric(12,2) NOT NULL,
    method character varying(255) DEFAULT 'cash'::character varying NOT NULL,
    reference_number character varying(255),
    notes text,
    paid_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: purchase_order_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.purchase_order_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: purchase_order_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.purchase_order_payments_id_seq OWNED BY public.purchase_order_payments.id;


--
-- Name: purchase_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_orders (
    id bigint NOT NULL,
    po_number character varying(255) NOT NULL,
    supplier_id bigint,
    warehouse_id bigint NOT NULL,
    user_id bigint,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    order_date date NOT NULL,
    expected_date date,
    received_date date,
    subtotal numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    shipping_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    total numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    payment_status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    CONSTRAINT purchase_orders_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'sent'::character varying, 'partial'::character varying, 'received'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: purchase_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.purchase_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: purchase_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.purchase_orders_id_seq OWNED BY public.purchase_orders.id;


--
-- Name: sales_order_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sales_order_items (
    id bigint NOT NULL,
    sales_order_id bigint NOT NULL,
    product_id bigint,
    warehouse_id bigint NOT NULL,
    quantity integer NOT NULL,
    unit_price numeric(10,2) NOT NULL,
    discount_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    discount_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    tax_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    subtotal numeric(10,2) NOT NULL,
    total numeric(10,2) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    variant_id bigint,
    unit_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    seller_id bigint,
    notes text,
    is_custom_line boolean DEFAULT false NOT NULL,
    custom_description character varying(255)
);


--
-- Name: sales_order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sales_order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sales_order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sales_order_items_id_seq OWNED BY public.sales_order_items.id;


--
-- Name: sales_order_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sales_order_payments (
    id bigint NOT NULL,
    sales_order_id bigint NOT NULL,
    amount numeric(12,2) NOT NULL,
    method character varying(50) NOT NULL,
    notes text,
    paid_at timestamp(0) without time zone NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: sales_order_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sales_order_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sales_order_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sales_order_payments_id_seq OWNED BY public.sales_order_payments.id;


--
-- Name: sales_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sales_orders (
    id bigint NOT NULL,
    order_number character varying(50) NOT NULL,
    customer_id bigint,
    user_id bigint NOT NULL,
    date date NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    subtotal numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    discount_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    shipping_cost numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    total numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    payment_status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    payment_method character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    pos_session_id bigint,
    audit_logs json,
    internal_notes text,
    sent_to_warehouse_at timestamp(0) without time zone,
    prepared_at timestamp(0) without time zone,
    prepared_by bigint,
    CONSTRAINT sales_orders_payment_method_check CHECK (((payment_method)::text = ANY ((ARRAY['cash'::character varying, 'card'::character varying, 'transfer'::character varying, 'credit'::character varying, 'multiple'::character varying])::text[]))),
    CONSTRAINT sales_orders_payment_status_check CHECK (((payment_status)::text = ANY ((ARRAY['pending'::character varying, 'partial'::character varying, 'paid'::character varying, 'refunded'::character varying])::text[]))),
    CONSTRAINT sales_orders_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'pending'::character varying, 'confirmed'::character varying, 'processing'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: sales_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sales_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sales_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sales_orders_id_seq OWNED BY public.sales_orders.id;


--
-- Name: sales_quote_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sales_quote_items (
    id bigint NOT NULL,
    sales_quote_id bigint NOT NULL,
    product_id bigint,
    variant_id bigint,
    warehouse_id bigint NOT NULL,
    quantity integer NOT NULL,
    unit_price numeric(10,2) NOT NULL,
    discount_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    discount_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    tax_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    subtotal numeric(10,2) NOT NULL,
    total numeric(10,2) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    notes text,
    is_custom_line boolean DEFAULT false NOT NULL,
    custom_description character varying(255)
);


--
-- Name: sales_quote_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sales_quote_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sales_quote_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sales_quote_items_id_seq OWNED BY public.sales_quote_items.id;


--
-- Name: sales_quotes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sales_quotes (
    id bigint NOT NULL,
    quote_number character varying(50) NOT NULL,
    customer_id bigint,
    user_id bigint NOT NULL,
    warehouse_id bigint,
    date date NOT NULL,
    valid_until date,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    subtotal numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    discount_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    total numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    converted_order_id bigint,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    pos_session_id bigint,
    internal_notes text,
    sent_to_cashier_at timestamp(0) without time zone
);


--
-- Name: sales_quotes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sales_quotes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sales_quotes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sales_quotes_id_seq OWNED BY public.sales_quotes.id;


--
-- Name: sales_return_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sales_return_items (
    id bigint NOT NULL,
    sales_return_id bigint NOT NULL,
    sales_order_item_id bigint NOT NULL,
    product_id bigint NOT NULL,
    variant_id bigint,
    quantity integer NOT NULL,
    unit_price numeric(12,2) NOT NULL,
    subtotal numeric(12,2) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id bigint,
    seller_id bigint
);


--
-- Name: sales_return_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sales_return_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sales_return_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sales_return_items_id_seq OWNED BY public.sales_return_items.id;


--
-- Name: sales_returns; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sales_returns (
    id bigint NOT NULL,
    return_number character varying(255) NOT NULL,
    sales_order_id bigint NOT NULL,
    warehouse_id bigint NOT NULL,
    customer_id bigint,
    user_id bigint NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    reason character varying(255),
    total_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    refund_method character varying(50) DEFAULT 'balance'::character varying NOT NULL,
    refund_notes text,
    CONSTRAINT sales_returns_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[])))
);


--
-- Name: sales_returns_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sales_returns_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sales_returns_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sales_returns_id_seq OWNED BY public.sales_returns.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.settings (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    value text,
    type character varying(255) DEFAULT 'string'::character varying NOT NULL,
    "group" character varying(255) DEFAULT 'general'::character varying NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.settings_id_seq OWNED BY public.settings.id;


--
-- Name: suppliers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.suppliers (
    id bigint NOT NULL,
    name character varying(150) NOT NULL,
    contact_name character varying(100),
    email character varying(100),
    phone character varying(20),
    address text,
    payment_terms character varying(100),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    city character varying(100),
    country character varying(100) DEFAULT 'Panamá'::character varying NOT NULL,
    lead_time_days integer,
    notes text,
    deleted_at timestamp(0) without time zone,
    CONSTRAINT suppliers_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying])::text[])))
);


--
-- Name: COLUMN suppliers.payment_terms; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.suppliers.payment_terms IS 'Net 30, Net 60, etc.';


--
-- Name: suppliers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.suppliers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: suppliers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.suppliers_id_seq OWNED BY public.suppliers.id;


--
-- Name: supply_list_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.supply_list_items (
    id bigint NOT NULL,
    product_id bigint NOT NULL,
    variant_id bigint,
    supplier_id bigint,
    added_by bigint NOT NULL,
    quantity_needed numeric(10,2) DEFAULT '1'::numeric NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    unit_cost numeric(12,2) DEFAULT '0'::numeric,
    tax_rate numeric(5,2) DEFAULT '7'::numeric,
    sale_price numeric(12,2) DEFAULT '0'::numeric,
    stock_added_qty integer DEFAULT 0 NOT NULL
);


--
-- Name: supply_list_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.supply_list_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: supply_list_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.supply_list_items_id_seq OWNED BY public.supply_list_items.id;


--
-- Name: system_sequences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.system_sequences (
    id bigint NOT NULL,
    key character varying(50) NOT NULL,
    next_value bigint DEFAULT '1'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: system_sequences_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.system_sequences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: system_sequences_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.system_sequences_id_seq OWNED BY public.system_sequences.id;


--
-- Name: user_warehouse; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_warehouse (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    warehouse_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: user_warehouse_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_warehouse_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_warehouse_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_warehouse_id_seq OWNED BY public.user_warehouse.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    two_factor_secret text,
    two_factor_recovery_codes text,
    two_factor_confirmed_at timestamp(0) without time zone,
    current_warehouse_id bigint,
    role character varying(255) DEFAULT 'cashier'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    username character varying(255)
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: vehicle_chassis; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_chassis (
    id bigint NOT NULL,
    code character varying(50) NOT NULL,
    name character varying(255),
    model_id bigint,
    year_from character varying(4),
    year_to character varying(4),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: vehicle_chassis_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vehicle_chassis_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vehicle_chassis_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vehicle_chassis_id_seq OWNED BY public.vehicle_chassis.id;


--
-- Name: vehicle_chassis_model; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_chassis_model (
    id bigint NOT NULL,
    chassis_id bigint NOT NULL,
    model_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: vehicle_chassis_model_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vehicle_chassis_model_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vehicle_chassis_model_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vehicle_chassis_model_id_seq OWNED BY public.vehicle_chassis_model.id;


--
-- Name: vehicle_engine_model; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_engine_model (
    id bigint NOT NULL,
    engine_id bigint NOT NULL,
    model_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: vehicle_engine_model_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vehicle_engine_model_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vehicle_engine_model_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vehicle_engine_model_id_seq OWNED BY public.vehicle_engine_model.id;


--
-- Name: vehicle_engines; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_engines (
    id bigint NOT NULL,
    model_id bigint,
    engine_code character varying(50) NOT NULL,
    displacement character varying(20),
    fuel_type character varying(50),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    aspiration character varying(50)
);


--
-- Name: COLUMN vehicle_engines.displacement; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_engines.displacement IS 'e.g., 3.5L, 2000cc';


--
-- Name: COLUMN vehicle_engines.fuel_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.vehicle_engines.fuel_type IS 'Gasoline, Diesel, Hybrid, etc.';


--
-- Name: vehicle_engines_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vehicle_engines_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vehicle_engines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vehicle_engines_id_seq OWNED BY public.vehicle_engines.id;


--
-- Name: vehicle_makes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_makes (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: vehicle_makes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vehicle_makes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vehicle_makes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vehicle_makes_id_seq OWNED BY public.vehicle_makes.id;


--
-- Name: vehicle_models; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_models (
    id bigint NOT NULL,
    make_id bigint NOT NULL,
    name character varying(100) NOT NULL,
    year_from integer NOT NULL,
    year_to integer NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    generation character varying(20)
);


--
-- Name: vehicle_models_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vehicle_models_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vehicle_models_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vehicle_models_id_seq OWNED BY public.vehicle_models.id;


--
-- Name: vehicle_vins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vehicle_vins (
    id bigint NOT NULL,
    engine_id bigint,
    vin character varying(40) NOT NULL,
    notes character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    model_id bigint
);


--
-- Name: vehicle_vins_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vehicle_vins_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vehicle_vins_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vehicle_vins_id_seq OWNED BY public.vehicle_vins.id;


--
-- Name: warehouse_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.warehouse_batches (
    id bigint NOT NULL,
    warehouse_id bigint NOT NULL,
    product_batch_id bigint NOT NULL,
    quantity numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    quantity_reserved numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: warehouse_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.warehouse_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: warehouse_batches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.warehouse_batches_id_seq OWNED BY public.warehouse_batches.id;


--
-- Name: warehouses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.warehouses (
    id bigint NOT NULL,
    code character varying(20) NOT NULL,
    name character varying(100) NOT NULL,
    address text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    location text,
    capacity integer,
    notes text
);


--
-- Name: warehouses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.warehouses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: warehouses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.warehouses_id_seq OWNED BY public.warehouses.id;


--
-- Name: work_order_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.work_order_items (
    id bigint NOT NULL,
    work_order_id bigint NOT NULL,
    product_id bigint NOT NULL,
    variant_id bigint,
    warehouse_id bigint NOT NULL,
    quantity integer DEFAULT 1 NOT NULL,
    unit_price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    notes character varying(500),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: work_order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.work_order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: work_order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.work_order_items_id_seq OWNED BY public.work_order_items.id;


--
-- Name: work_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.work_orders (
    id bigint NOT NULL,
    folio character varying(30),
    customer_id bigint,
    warehouse_id bigint NOT NULL,
    created_by bigint NOT NULL,
    assigned_to bigint,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    vehicle_description character varying(255),
    notes text,
    labour_cost numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    estimated_delivery_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT work_orders_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'in_progress'::character varying, 'done'::character varying, 'delivered'::character varying, 'cancelled'::character varying])::text[])))
);


--
-- Name: work_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.work_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: work_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.work_orders_id_seq OWNED BY public.work_orders.id;


--
-- Name: activity_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_log ALTER COLUMN id SET DEFAULT nextval('public.activity_log_id_seq'::regclass);


--
-- Name: alternative_products id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alternative_products ALTER COLUMN id SET DEFAULT nextval('public.alternative_products_id_seq'::regclass);


--
-- Name: attribute_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attribute_definitions ALTER COLUMN id SET DEFAULT nextval('public.attribute_definitions_id_seq'::regclass);


--
-- Name: brand_sequences id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_sequences ALTER COLUMN id SET DEFAULT nextval('public.brand_sequences_id_seq'::regclass);


--
-- Name: brand_supplier id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_supplier ALTER COLUMN id SET DEFAULT nextval('public.brand_supplier_id_seq'::regclass);


--
-- Name: brands id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brands ALTER COLUMN id SET DEFAULT nextval('public.brands_id_seq'::regclass);


--
-- Name: cash_movements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_movements ALTER COLUMN id SET DEFAULT nextval('public.cash_movements_id_seq'::regclass);


--
-- Name: categories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories ALTER COLUMN id SET DEFAULT nextval('public.categories_id_seq'::regclass);


--
-- Name: customer_transactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_transactions ALTER COLUMN id SET DEFAULT nextval('public.customer_transactions_id_seq'::regclass);


--
-- Name: customers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers ALTER COLUMN id SET DEFAULT nextval('public.customers_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: inventory id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory ALTER COLUMN id SET DEFAULT nextval('public.inventory_id_seq'::regclass);


--
-- Name: inventory_movements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements ALTER COLUMN id SET DEFAULT nextval('public.inventory_movements_id_seq'::regclass);


--
-- Name: inventory_transfer_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfer_items ALTER COLUMN id SET DEFAULT nextval('public.inventory_transfer_items_id_seq'::regclass);


--
-- Name: inventory_transfers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfers ALTER COLUMN id SET DEFAULT nextval('public.inventory_transfers_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: oem_references id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oem_references ALTER COLUMN id SET DEFAULT nextval('public.oem_references_id_seq'::regclass);


--
-- Name: payment_methods id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_methods ALTER COLUMN id SET DEFAULT nextval('public.payment_methods_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: pos_sessions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_sessions ALTER COLUMN id SET DEFAULT nextval('public.pos_sessions_id_seq'::regclass);


--
-- Name: pricing_formulas id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pricing_formulas ALTER COLUMN id SET DEFAULT nextval('public.pricing_formulas_id_seq'::regclass);


--
-- Name: product_attributes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_attributes ALTER COLUMN id SET DEFAULT nextval('public.product_attributes_id_seq'::regclass);


--
-- Name: product_batches id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_batches ALTER COLUMN id SET DEFAULT nextval('public.product_batches_id_seq'::regclass);


--
-- Name: product_extra_categories id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_extra_categories ALTER COLUMN id SET DEFAULT nextval('public.product_extra_categories_id_seq'::regclass);


--
-- Name: product_inventory id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_inventory ALTER COLUMN id SET DEFAULT nextval('public.product_inventory_id_seq'::regclass);


--
-- Name: product_lines id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_lines ALTER COLUMN id SET DEFAULT nextval('public.product_lines_id_seq'::regclass);


--
-- Name: product_media id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_media ALTER COLUMN id SET DEFAULT nextval('public.product_media_id_seq'::regclass);


--
-- Name: product_pricing id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_pricing ALTER COLUMN id SET DEFAULT nextval('public.product_pricing_id_seq'::regclass);


--
-- Name: product_suppliers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_suppliers ALTER COLUMN id SET DEFAULT nextval('public.product_suppliers_id_seq'::regclass);


--
-- Name: product_variants id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_variants ALTER COLUMN id SET DEFAULT nextval('public.product_variants_id_seq'::regclass);


--
-- Name: product_vehicle_compatibility id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_vehicle_compatibility ALTER COLUMN id SET DEFAULT nextval('public.product_vehicle_compatibility_id_seq'::regclass);


--
-- Name: product_vins id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_vins ALTER COLUMN id SET DEFAULT nextval('public.product_vins_id_seq'::regclass);


--
-- Name: products id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products ALTER COLUMN id SET DEFAULT nextval('public.products_id_seq'::regclass);


--
-- Name: purchase_order_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items ALTER COLUMN id SET DEFAULT nextval('public.purchase_order_items_id_seq'::regclass);


--
-- Name: purchase_order_payments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_payments ALTER COLUMN id SET DEFAULT nextval('public.purchase_order_payments_id_seq'::regclass);


--
-- Name: purchase_orders id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders ALTER COLUMN id SET DEFAULT nextval('public.purchase_orders_id_seq'::regclass);


--
-- Name: sales_order_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_items ALTER COLUMN id SET DEFAULT nextval('public.sales_order_items_id_seq'::regclass);


--
-- Name: sales_order_payments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_payments ALTER COLUMN id SET DEFAULT nextval('public.sales_order_payments_id_seq'::regclass);


--
-- Name: sales_orders id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_orders ALTER COLUMN id SET DEFAULT nextval('public.sales_orders_id_seq'::regclass);


--
-- Name: sales_quote_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quote_items ALTER COLUMN id SET DEFAULT nextval('public.sales_quote_items_id_seq'::regclass);


--
-- Name: sales_quotes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quotes ALTER COLUMN id SET DEFAULT nextval('public.sales_quotes_id_seq'::regclass);


--
-- Name: sales_return_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_return_items ALTER COLUMN id SET DEFAULT nextval('public.sales_return_items_id_seq'::regclass);


--
-- Name: sales_returns id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_returns ALTER COLUMN id SET DEFAULT nextval('public.sales_returns_id_seq'::regclass);


--
-- Name: settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settings ALTER COLUMN id SET DEFAULT nextval('public.settings_id_seq'::regclass);


--
-- Name: suppliers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers ALTER COLUMN id SET DEFAULT nextval('public.suppliers_id_seq'::regclass);


--
-- Name: supply_list_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_list_items ALTER COLUMN id SET DEFAULT nextval('public.supply_list_items_id_seq'::regclass);


--
-- Name: system_sequences id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_sequences ALTER COLUMN id SET DEFAULT nextval('public.system_sequences_id_seq'::regclass);


--
-- Name: user_warehouse id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_warehouse ALTER COLUMN id SET DEFAULT nextval('public.user_warehouse_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: vehicle_chassis id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_chassis ALTER COLUMN id SET DEFAULT nextval('public.vehicle_chassis_id_seq'::regclass);


--
-- Name: vehicle_chassis_model id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_chassis_model ALTER COLUMN id SET DEFAULT nextval('public.vehicle_chassis_model_id_seq'::regclass);


--
-- Name: vehicle_engine_model id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_engine_model ALTER COLUMN id SET DEFAULT nextval('public.vehicle_engine_model_id_seq'::regclass);


--
-- Name: vehicle_engines id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_engines ALTER COLUMN id SET DEFAULT nextval('public.vehicle_engines_id_seq'::regclass);


--
-- Name: vehicle_makes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_makes ALTER COLUMN id SET DEFAULT nextval('public.vehicle_makes_id_seq'::regclass);


--
-- Name: vehicle_models id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_models ALTER COLUMN id SET DEFAULT nextval('public.vehicle_models_id_seq'::regclass);


--
-- Name: vehicle_vins id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_vins ALTER COLUMN id SET DEFAULT nextval('public.vehicle_vins_id_seq'::regclass);


--
-- Name: warehouse_batches id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouse_batches ALTER COLUMN id SET DEFAULT nextval('public.warehouse_batches_id_seq'::regclass);


--
-- Name: warehouses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses ALTER COLUMN id SET DEFAULT nextval('public.warehouses_id_seq'::regclass);


--
-- Name: work_order_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_order_items ALTER COLUMN id SET DEFAULT nextval('public.work_order_items_id_seq'::regclass);


--
-- Name: work_orders id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_orders ALTER COLUMN id SET DEFAULT nextval('public.work_orders_id_seq'::regclass);


--
-- Name: activity_log activity_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_log
    ADD CONSTRAINT activity_log_pkey PRIMARY KEY (id);


--
-- Name: alternative_products alternative_products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alternative_products
    ADD CONSTRAINT alternative_products_pkey PRIMARY KEY (id);


--
-- Name: alternative_products alternative_products_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alternative_products
    ADD CONSTRAINT alternative_products_unique UNIQUE (primary_product_id, alternative_product_id);


--
-- Name: attribute_definitions attribute_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.attribute_definitions
    ADD CONSTRAINT attribute_definitions_pkey PRIMARY KEY (id);


--
-- Name: brand_sequences brand_sequences_brand_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_sequences
    ADD CONSTRAINT brand_sequences_brand_id_unique UNIQUE (brand_id);


--
-- Name: brand_sequences brand_sequences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_sequences
    ADD CONSTRAINT brand_sequences_pkey PRIMARY KEY (id);


--
-- Name: brand_supplier brand_supplier_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_supplier
    ADD CONSTRAINT brand_supplier_pkey PRIMARY KEY (id);


--
-- Name: brand_supplier brand_supplier_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_supplier
    ADD CONSTRAINT brand_supplier_unique UNIQUE (brand_id, supplier_id);


--
-- Name: brands brands_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brands
    ADD CONSTRAINT brands_name_unique UNIQUE (name);


--
-- Name: brands brands_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brands
    ADD CONSTRAINT brands_pkey PRIMARY KEY (id);


--
-- Name: brands brands_prefix_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brands
    ADD CONSTRAINT brands_prefix_unique UNIQUE (prefix);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: cash_movements cash_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_movements
    ADD CONSTRAINT cash_movements_pkey PRIMARY KEY (id);


--
-- Name: categories categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (id);


--
-- Name: categories categories_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_slug_unique UNIQUE (slug);


--
-- Name: customer_transactions customer_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_transactions
    ADD CONSTRAINT customer_transactions_pkey PRIMARY KEY (id);


--
-- Name: customers customers_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_code_unique UNIQUE (code);


--
-- Name: customers customers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: inventory_movements inventory_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_pkey PRIMARY KEY (id);


--
-- Name: inventory inventory_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_pkey PRIMARY KEY (id);


--
-- Name: inventory_transfer_items inventory_transfer_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfer_items
    ADD CONSTRAINT inventory_transfer_items_pkey PRIMARY KEY (id);


--
-- Name: inventory_transfers inventory_transfers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfers
    ADD CONSTRAINT inventory_transfers_pkey PRIMARY KEY (id);


--
-- Name: inventory_transfers inventory_transfers_reference_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfers
    ADD CONSTRAINT inventory_transfers_reference_unique UNIQUE (reference);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: oem_references oem_references_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oem_references
    ADD CONSTRAINT oem_references_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: payment_methods payment_methods_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_methods
    ADD CONSTRAINT payment_methods_code_unique UNIQUE (code);


--
-- Name: payment_methods payment_methods_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.payment_methods
    ADD CONSTRAINT payment_methods_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: pos_sessions pos_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_sessions
    ADD CONSTRAINT pos_sessions_pkey PRIMARY KEY (id);


--
-- Name: pricing_formulas pricing_formulas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pricing_formulas
    ADD CONSTRAINT pricing_formulas_pkey PRIMARY KEY (id);


--
-- Name: product_attributes product_attributes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_attributes
    ADD CONSTRAINT product_attributes_pkey PRIMARY KEY (id);


--
-- Name: product_batches product_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_batches
    ADD CONSTRAINT product_batches_pkey PRIMARY KEY (id);


--
-- Name: product_batches product_batches_product_id_batch_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_batches
    ADD CONSTRAINT product_batches_product_id_batch_number_unique UNIQUE (product_id, batch_number);


--
-- Name: product_extra_categories product_extra_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_extra_categories
    ADD CONSTRAINT product_extra_categories_pkey PRIMARY KEY (id);


--
-- Name: product_extra_categories product_extra_categories_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_extra_categories
    ADD CONSTRAINT product_extra_categories_unique UNIQUE (product_id, category_id);


--
-- Name: product_inventory product_inventory_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_inventory
    ADD CONSTRAINT product_inventory_pkey PRIMARY KEY (id);


--
-- Name: product_inventory product_inventory_product_id_warehouse_id_variant_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_inventory
    ADD CONSTRAINT product_inventory_product_id_warehouse_id_variant_id_unique UNIQUE (product_id, warehouse_id, variant_id);


--
-- Name: product_lines product_lines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_lines
    ADD CONSTRAINT product_lines_pkey PRIMARY KEY (id);


--
-- Name: product_media product_media_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_media
    ADD CONSTRAINT product_media_pkey PRIMARY KEY (id);


--
-- Name: product_pricing product_pricing_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_pricing
    ADD CONSTRAINT product_pricing_pkey PRIMARY KEY (id);


--
-- Name: product_pricing product_pricing_product_variant_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_pricing
    ADD CONSTRAINT product_pricing_product_variant_unique UNIQUE (product_id, variant_id);


--
-- Name: product_suppliers product_supplier_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_suppliers
    ADD CONSTRAINT product_supplier_unique UNIQUE (product_id, supplier_id);


--
-- Name: product_suppliers product_suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_suppliers
    ADD CONSTRAINT product_suppliers_pkey PRIMARY KEY (id);


--
-- Name: product_variants product_variants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_variants
    ADD CONSTRAINT product_variants_pkey PRIMARY KEY (id);


--
-- Name: product_variants product_variants_product_id_brand_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_variants
    ADD CONSTRAINT product_variants_product_id_brand_id_unique UNIQUE (product_id, brand_id);


--
-- Name: product_variants product_variants_system_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_variants
    ADD CONSTRAINT product_variants_system_id_unique UNIQUE (system_id);


--
-- Name: product_vehicle_compatibility product_vehicle_compatibility_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_vehicle_compatibility
    ADD CONSTRAINT product_vehicle_compatibility_pkey PRIMARY KEY (id);


--
-- Name: product_vins product_vins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_vins
    ADD CONSTRAINT product_vins_pkey PRIMARY KEY (id);


--
-- Name: product_vins product_vins_product_id_vin_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_vins
    ADD CONSTRAINT product_vins_product_id_vin_unique UNIQUE (product_id, vin);


--
-- Name: inventory product_warehouse_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT product_warehouse_unique UNIQUE (product_id, warehouse_id);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: products products_system_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_system_id_unique UNIQUE (system_id);


--
-- Name: purchase_order_items purchase_order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_pkey PRIMARY KEY (id);


--
-- Name: purchase_order_payments purchase_order_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_payments
    ADD CONSTRAINT purchase_order_payments_pkey PRIMARY KEY (id);


--
-- Name: purchase_orders purchase_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_pkey PRIMARY KEY (id);


--
-- Name: purchase_orders purchase_orders_po_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_po_number_unique UNIQUE (po_number);


--
-- Name: sales_order_items sales_order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_items
    ADD CONSTRAINT sales_order_items_pkey PRIMARY KEY (id);


--
-- Name: sales_order_payments sales_order_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_payments
    ADD CONSTRAINT sales_order_payments_pkey PRIMARY KEY (id);


--
-- Name: sales_orders sales_orders_order_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_orders
    ADD CONSTRAINT sales_orders_order_number_unique UNIQUE (order_number);


--
-- Name: sales_orders sales_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_orders
    ADD CONSTRAINT sales_orders_pkey PRIMARY KEY (id);


--
-- Name: sales_quote_items sales_quote_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quote_items
    ADD CONSTRAINT sales_quote_items_pkey PRIMARY KEY (id);


--
-- Name: sales_quotes sales_quotes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quotes
    ADD CONSTRAINT sales_quotes_pkey PRIMARY KEY (id);


--
-- Name: sales_quotes sales_quotes_quote_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quotes
    ADD CONSTRAINT sales_quotes_quote_number_unique UNIQUE (quote_number);


--
-- Name: sales_return_items sales_return_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_return_items
    ADD CONSTRAINT sales_return_items_pkey PRIMARY KEY (id);


--
-- Name: sales_returns sales_returns_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_returns
    ADD CONSTRAINT sales_returns_pkey PRIMARY KEY (id);


--
-- Name: sales_returns sales_returns_return_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_returns
    ADD CONSTRAINT sales_returns_return_number_unique UNIQUE (return_number);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: settings settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settings
    ADD CONSTRAINT settings_key_unique UNIQUE (key);


--
-- Name: settings settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settings
    ADD CONSTRAINT settings_pkey PRIMARY KEY (id);


--
-- Name: suppliers suppliers_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_name_unique UNIQUE (name);


--
-- Name: suppliers suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: supply_list_items supply_list_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_list_items
    ADD CONSTRAINT supply_list_items_pkey PRIMARY KEY (id);


--
-- Name: system_sequences system_sequences_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_sequences
    ADD CONSTRAINT system_sequences_key_unique UNIQUE (key);


--
-- Name: system_sequences system_sequences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_sequences
    ADD CONSTRAINT system_sequences_pkey PRIMARY KEY (id);


--
-- Name: user_warehouse user_warehouse_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_warehouse
    ADD CONSTRAINT user_warehouse_pkey PRIMARY KEY (id);


--
-- Name: user_warehouse user_warehouse_user_id_warehouse_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_warehouse
    ADD CONSTRAINT user_warehouse_user_id_warehouse_id_unique UNIQUE (user_id, warehouse_id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_unique UNIQUE (username);


--
-- Name: vehicle_chassis_model vehicle_chassis_model_chassis_id_model_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_chassis_model
    ADD CONSTRAINT vehicle_chassis_model_chassis_id_model_id_unique UNIQUE (chassis_id, model_id);


--
-- Name: vehicle_chassis_model vehicle_chassis_model_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_chassis_model
    ADD CONSTRAINT vehicle_chassis_model_pkey PRIMARY KEY (id);


--
-- Name: vehicle_chassis vehicle_chassis_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_chassis
    ADD CONSTRAINT vehicle_chassis_pkey PRIMARY KEY (id);


--
-- Name: vehicle_engine_model vehicle_engine_model_engine_id_model_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_engine_model
    ADD CONSTRAINT vehicle_engine_model_engine_id_model_id_unique UNIQUE (engine_id, model_id);


--
-- Name: vehicle_engine_model vehicle_engine_model_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_engine_model
    ADD CONSTRAINT vehicle_engine_model_pkey PRIMARY KEY (id);


--
-- Name: vehicle_engines vehicle_engines_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_engines
    ADD CONSTRAINT vehicle_engines_pkey PRIMARY KEY (id);


--
-- Name: vehicle_makes vehicle_makes_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_makes
    ADD CONSTRAINT vehicle_makes_name_unique UNIQUE (name);


--
-- Name: vehicle_makes vehicle_makes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_makes
    ADD CONSTRAINT vehicle_makes_pkey PRIMARY KEY (id);


--
-- Name: vehicle_models vehicle_models_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_models
    ADD CONSTRAINT vehicle_models_pkey PRIMARY KEY (id);


--
-- Name: vehicle_vins vehicle_vins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_vins
    ADD CONSTRAINT vehicle_vins_pkey PRIMARY KEY (id);


--
-- Name: vehicle_vins vehicle_vins_vin_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_vins
    ADD CONSTRAINT vehicle_vins_vin_unique UNIQUE (vin);


--
-- Name: warehouse_batches warehouse_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouse_batches
    ADD CONSTRAINT warehouse_batches_pkey PRIMARY KEY (id);


--
-- Name: warehouse_batches warehouse_batches_warehouse_id_product_batch_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouse_batches
    ADD CONSTRAINT warehouse_batches_warehouse_id_product_batch_id_unique UNIQUE (warehouse_id, product_batch_id);


--
-- Name: warehouses warehouses_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_code_unique UNIQUE (code);


--
-- Name: warehouses warehouses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouses
    ADD CONSTRAINT warehouses_pkey PRIMARY KEY (id);


--
-- Name: work_order_items work_order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_order_items
    ADD CONSTRAINT work_order_items_pkey PRIMARY KEY (id);


--
-- Name: work_orders work_orders_folio_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_folio_unique UNIQUE (folio);


--
-- Name: work_orders work_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_pkey PRIMARY KEY (id);


--
-- Name: activity_log_log_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_log_log_name_index ON public.activity_log USING btree (log_name);


--
-- Name: alternative_products_alternative_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX alternative_products_alternative_product_id_index ON public.alternative_products USING btree (alternative_product_id);


--
-- Name: alternative_products_primary_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX alternative_products_primary_product_id_index ON public.alternative_products USING btree (primary_product_id);


--
-- Name: attribute_definitions_data_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX attribute_definitions_data_type_index ON public.attribute_definitions USING btree (data_type);


--
-- Name: brands_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX brands_status_index ON public.brands USING btree (status);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: categories_parent_id_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX categories_parent_id_priority_index ON public.categories USING btree (parent_id, priority);


--
-- Name: causer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX causer ON public.activity_log USING btree (causer_type, causer_id);


--
-- Name: customer_transactions_customer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_transactions_customer_id_index ON public.customer_transactions USING btree (customer_id);


--
-- Name: customer_transactions_reference_type_reference_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_transactions_reference_type_reference_id_index ON public.customer_transactions USING btree (reference_type, reference_id);


--
-- Name: customer_transactions_transaction_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_transactions_transaction_date_index ON public.customer_transactions USING btree (transaction_date);


--
-- Name: customer_transactions_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_transactions_type_index ON public.customer_transactions USING btree (type);


--
-- Name: customers_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_code_index ON public.customers USING btree (code);


--
-- Name: customers_email_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_email_index ON public.customers USING btree (email);


--
-- Name: customers_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_status_index ON public.customers USING btree (status);


--
-- Name: customers_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customers_type_index ON public.customers USING btree (type);


--
-- Name: inventory_movements_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_movements_created_at_index ON public.inventory_movements USING btree (created_at);


--
-- Name: inventory_movements_movement_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_movements_movement_type_index ON public.inventory_movements USING btree (movement_type);


--
-- Name: inventory_movements_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_movements_product_id_index ON public.inventory_movements USING btree (product_id);


--
-- Name: inventory_movements_reference_type_reference_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_movements_reference_type_reference_id_index ON public.inventory_movements USING btree (reference_type, reference_id);


--
-- Name: inventory_movements_supplier_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_movements_supplier_id_index ON public.inventory_movements USING btree (supplier_id);


--
-- Name: inventory_movements_variant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_movements_variant_id_index ON public.inventory_movements USING btree (variant_id);


--
-- Name: inventory_movements_warehouse_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_movements_warehouse_id_index ON public.inventory_movements USING btree (warehouse_id);


--
-- Name: inventory_warehouse_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_warehouse_id_index ON public.inventory USING btree (warehouse_id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: oem_references_oem_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oem_references_oem_code_index ON public.oem_references USING btree (oem_code);


--
-- Name: oem_references_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oem_references_product_id_index ON public.oem_references USING btree (product_id);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: product_attributes_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_attributes_name_index ON public.product_attributes USING btree (name);


--
-- Name: product_attributes_name_value_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_attributes_name_value_index ON public.product_attributes USING btree (name, value);


--
-- Name: product_attributes_value_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_attributes_value_index ON public.product_attributes USING btree (value);


--
-- Name: product_attributes_variant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_attributes_variant_id_index ON public.product_attributes USING btree (variant_id);


--
-- Name: product_extra_categories_category_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_extra_categories_category_id_index ON public.product_extra_categories USING btree (category_id);


--
-- Name: product_inventory_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_inventory_product_id_index ON public.product_inventory USING btree (product_id);


--
-- Name: product_inventory_quantity_available_reorder_point_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_inventory_quantity_available_reorder_point_index ON public.product_inventory USING btree (quantity_available, reorder_point);


--
-- Name: product_inventory_variant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_inventory_variant_id_index ON public.product_inventory USING btree (variant_id);


--
-- Name: product_inventory_warehouse_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_inventory_warehouse_id_index ON public.product_inventory USING btree (warehouse_id);


--
-- Name: product_lines_brand_id_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_lines_brand_id_priority_index ON public.product_lines USING btree (brand_id, priority);


--
-- Name: product_media_product_id_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_media_product_id_category_index ON public.product_media USING btree (product_id, category);


--
-- Name: product_media_product_id_media_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_media_product_id_media_type_index ON public.product_media USING btree (product_id, media_type);


--
-- Name: product_pricing_base_price_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_pricing_base_price_index ON public.product_pricing USING btree (base_price);


--
-- Name: product_pricing_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_pricing_product_id_index ON public.product_pricing USING btree (product_id);


--
-- Name: product_pricing_variant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_pricing_variant_id_index ON public.product_pricing USING btree (variant_id);


--
-- Name: product_suppliers_product_id_is_preferred_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_suppliers_product_id_is_preferred_index ON public.product_suppliers USING btree (product_id, is_preferred);


--
-- Name: product_suppliers_variant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_suppliers_variant_id_index ON public.product_suppliers USING btree (variant_id);


--
-- Name: product_variants_product_id_is_default_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_variants_product_id_is_default_index ON public.product_variants USING btree (product_id, is_default);


--
-- Name: product_variants_sku_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_variants_sku_index ON public.product_variants USING btree (sku);


--
-- Name: product_variants_upc_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_variants_upc_index ON public.product_variants USING btree (upc);


--
-- Name: product_vehicle_compat_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_vehicle_compat_idx ON public.product_vehicle_compatibility USING btree (product_id, engine_id, chassis_id);


--
-- Name: product_vehicle_compatibility_engine_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_vehicle_compatibility_engine_id_index ON public.product_vehicle_compatibility USING btree (engine_id);


--
-- Name: product_vehicle_compatibility_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_vehicle_compatibility_product_id_index ON public.product_vehicle_compatibility USING btree (product_id);


--
-- Name: product_vins_vin_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_vins_vin_index ON public.product_vins USING btree (vin);


--
-- Name: products_category_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_category_id_index ON public.products USING btree (category_id);


--
-- Name: products_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX products_status_index ON public.products USING btree (status);


--
-- Name: purchase_order_items_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX purchase_order_items_product_id_index ON public.purchase_order_items USING btree (product_id);


--
-- Name: purchase_order_items_purchase_order_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX purchase_order_items_purchase_order_id_index ON public.purchase_order_items USING btree (purchase_order_id);


--
-- Name: purchase_order_items_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX purchase_order_items_status_index ON public.purchase_order_items USING btree (status);


--
-- Name: purchase_order_items_supplier_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX purchase_order_items_supplier_id_index ON public.purchase_order_items USING btree (supplier_id);


--
-- Name: purchase_order_items_variant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX purchase_order_items_variant_id_index ON public.purchase_order_items USING btree (variant_id);


--
-- Name: purchase_orders_order_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX purchase_orders_order_date_index ON public.purchase_orders USING btree (order_date);


--
-- Name: purchase_orders_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX purchase_orders_status_index ON public.purchase_orders USING btree (status);


--
-- Name: purchase_orders_supplier_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX purchase_orders_supplier_id_index ON public.purchase_orders USING btree (supplier_id);


--
-- Name: sales_order_items_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_order_items_product_id_index ON public.sales_order_items USING btree (product_id);


--
-- Name: sales_order_items_sales_order_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_order_items_sales_order_id_index ON public.sales_order_items USING btree (sales_order_id);


--
-- Name: sales_order_items_variant_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_order_items_variant_id_index ON public.sales_order_items USING btree (variant_id);


--
-- Name: sales_order_items_warehouse_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_order_items_warehouse_id_index ON public.sales_order_items USING btree (warehouse_id);


--
-- Name: sales_order_payments_method_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_order_payments_method_index ON public.sales_order_payments USING btree (method);


--
-- Name: sales_order_payments_paid_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_order_payments_paid_at_index ON public.sales_order_payments USING btree (paid_at);


--
-- Name: sales_order_payments_sales_order_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_order_payments_sales_order_id_index ON public.sales_order_payments USING btree (sales_order_id);


--
-- Name: sales_orders_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_orders_created_at_index ON public.sales_orders USING btree (created_at);


--
-- Name: sales_orders_customer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_orders_customer_id_index ON public.sales_orders USING btree (customer_id);


--
-- Name: sales_orders_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_orders_date_index ON public.sales_orders USING btree (date);


--
-- Name: sales_orders_order_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_orders_order_number_index ON public.sales_orders USING btree (order_number);


--
-- Name: sales_orders_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_orders_status_index ON public.sales_orders USING btree (status);


--
-- Name: sales_quote_items_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_quote_items_product_id_index ON public.sales_quote_items USING btree (product_id);


--
-- Name: sales_quote_items_sales_quote_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_quote_items_sales_quote_id_index ON public.sales_quote_items USING btree (sales_quote_id);


--
-- Name: sales_quotes_customer_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_quotes_customer_id_index ON public.sales_quotes USING btree (customer_id);


--
-- Name: sales_quotes_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_quotes_date_index ON public.sales_quotes USING btree (date);


--
-- Name: sales_quotes_quote_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_quotes_quote_number_index ON public.sales_quotes USING btree (quote_number);


--
-- Name: sales_quotes_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_quotes_status_index ON public.sales_quotes USING btree (status);


--
-- Name: sales_returns_sales_order_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sales_returns_sales_order_id_status_index ON public.sales_returns USING btree (sales_order_id, status);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: subject; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX subject ON public.activity_log USING btree (subject_type, subject_id);


--
-- Name: suppliers_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX suppliers_status_index ON public.suppliers USING btree (status);


--
-- Name: vehicle_chassis_model_id_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_chassis_model_id_code_index ON public.vehicle_chassis USING btree (model_id, code);


--
-- Name: vehicle_chassis_model_model_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_chassis_model_model_id_index ON public.vehicle_chassis_model USING btree (model_id);


--
-- Name: vehicle_engine_model_model_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_engine_model_model_id_index ON public.vehicle_engine_model USING btree (model_id);


--
-- Name: vehicle_engines_model_id_engine_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_engines_model_id_engine_code_index ON public.vehicle_engines USING btree (model_id, engine_code);


--
-- Name: vehicle_models_make_id_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_models_make_id_name_index ON public.vehicle_models USING btree (make_id, name);


--
-- Name: vehicle_vins_engine_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vehicle_vins_engine_id_index ON public.vehicle_vins USING btree (engine_id);


--
-- Name: warehouses_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX warehouses_is_active_index ON public.warehouses USING btree (is_active);


--
-- Name: alternative_products alternative_products_alternative_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alternative_products
    ADD CONSTRAINT alternative_products_alternative_product_id_foreign FOREIGN KEY (alternative_product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: alternative_products alternative_products_primary_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alternative_products
    ADD CONSTRAINT alternative_products_primary_product_id_foreign FOREIGN KEY (primary_product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: brand_sequences brand_sequences_brand_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_sequences
    ADD CONSTRAINT brand_sequences_brand_id_foreign FOREIGN KEY (brand_id) REFERENCES public.brands(id) ON DELETE CASCADE;


--
-- Name: brand_supplier brand_supplier_brand_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_supplier
    ADD CONSTRAINT brand_supplier_brand_id_foreign FOREIGN KEY (brand_id) REFERENCES public.brands(id) ON DELETE CASCADE;


--
-- Name: brand_supplier brand_supplier_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.brand_supplier
    ADD CONSTRAINT brand_supplier_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE CASCADE;


--
-- Name: cash_movements cash_movements_pos_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_movements
    ADD CONSTRAINT cash_movements_pos_session_id_foreign FOREIGN KEY (pos_session_id) REFERENCES public.pos_sessions(id) ON DELETE CASCADE;


--
-- Name: cash_movements cash_movements_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cash_movements
    ADD CONSTRAINT cash_movements_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: categories categories_parent_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_parent_id_foreign FOREIGN KEY (parent_id) REFERENCES public.categories(id) ON DELETE CASCADE;


--
-- Name: customer_transactions customer_transactions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_transactions
    ADD CONSTRAINT customer_transactions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: customer_transactions customer_transactions_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_transactions
    ADD CONSTRAINT customer_transactions_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE CASCADE;


--
-- Name: inventory_movements inventory_movements_destination_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_destination_warehouse_id_foreign FOREIGN KEY (destination_warehouse_id) REFERENCES public.warehouses(id) ON DELETE CASCADE;


--
-- Name: inventory_movements inventory_movements_product_batch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_product_batch_id_foreign FOREIGN KEY (product_batch_id) REFERENCES public.product_batches(id) ON DELETE SET NULL;


--
-- Name: inventory_movements inventory_movements_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: inventory_movements inventory_movements_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: inventory_movements inventory_movements_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: inventory_movements inventory_movements_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE SET NULL;


--
-- Name: inventory_movements inventory_movements_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE CASCADE;


--
-- Name: inventory inventory_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: inventory_transfer_items inventory_transfer_items_inventory_transfer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfer_items
    ADD CONSTRAINT inventory_transfer_items_inventory_transfer_id_foreign FOREIGN KEY (inventory_transfer_id) REFERENCES public.inventory_transfers(id) ON DELETE CASCADE;


--
-- Name: inventory_transfer_items inventory_transfer_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfer_items
    ADD CONSTRAINT inventory_transfer_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: inventory_transfer_items inventory_transfer_items_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfer_items
    ADD CONSTRAINT inventory_transfer_items_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE SET NULL;


--
-- Name: inventory_transfers inventory_transfers_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfers
    ADD CONSTRAINT inventory_transfers_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: inventory_transfers inventory_transfers_from_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfers
    ADD CONSTRAINT inventory_transfers_from_warehouse_id_foreign FOREIGN KEY (from_warehouse_id) REFERENCES public.warehouses(id) ON DELETE RESTRICT;


--
-- Name: inventory_transfers inventory_transfers_received_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfers
    ADD CONSTRAINT inventory_transfers_received_by_foreign FOREIGN KEY (received_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: inventory_transfers inventory_transfers_to_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_transfers
    ADD CONSTRAINT inventory_transfers_to_warehouse_id_foreign FOREIGN KEY (to_warehouse_id) REFERENCES public.warehouses(id) ON DELETE RESTRICT;


--
-- Name: inventory inventory_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory
    ADD CONSTRAINT inventory_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE CASCADE;


--
-- Name: oem_references oem_references_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oem_references
    ADD CONSTRAINT oem_references_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: pos_sessions pos_sessions_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_sessions
    ADD CONSTRAINT pos_sessions_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: pos_sessions pos_sessions_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_sessions
    ADD CONSTRAINT pos_sessions_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE SET NULL;


--
-- Name: product_attributes product_attributes_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_attributes
    ADD CONSTRAINT product_attributes_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_attributes product_attributes_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_attributes
    ADD CONSTRAINT product_attributes_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE CASCADE;


--
-- Name: product_batches product_batches_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_batches
    ADD CONSTRAINT product_batches_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_batches product_batches_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_batches
    ADD CONSTRAINT product_batches_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: product_extra_categories product_extra_categories_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_extra_categories
    ADD CONSTRAINT product_extra_categories_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.categories(id) ON DELETE CASCADE;


--
-- Name: product_extra_categories product_extra_categories_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_extra_categories
    ADD CONSTRAINT product_extra_categories_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_inventory product_inventory_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_inventory
    ADD CONSTRAINT product_inventory_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_inventory product_inventory_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_inventory
    ADD CONSTRAINT product_inventory_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE CASCADE;


--
-- Name: product_inventory product_inventory_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_inventory
    ADD CONSTRAINT product_inventory_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE CASCADE;


--
-- Name: product_lines product_lines_brand_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_lines
    ADD CONSTRAINT product_lines_brand_id_foreign FOREIGN KEY (brand_id) REFERENCES public.brands(id) ON DELETE CASCADE;


--
-- Name: product_media product_media_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_media
    ADD CONSTRAINT product_media_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_pricing product_pricing_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_pricing
    ADD CONSTRAINT product_pricing_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_pricing product_pricing_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_pricing
    ADD CONSTRAINT product_pricing_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE CASCADE;


--
-- Name: product_suppliers product_suppliers_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_suppliers
    ADD CONSTRAINT product_suppliers_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_suppliers product_suppliers_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_suppliers
    ADD CONSTRAINT product_suppliers_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE CASCADE;


--
-- Name: product_suppliers product_suppliers_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_suppliers
    ADD CONSTRAINT product_suppliers_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE CASCADE;


--
-- Name: product_variants product_variants_brand_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_variants
    ADD CONSTRAINT product_variants_brand_id_foreign FOREIGN KEY (brand_id) REFERENCES public.brands(id) ON DELETE RESTRICT;


--
-- Name: product_variants product_variants_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_variants
    ADD CONSTRAINT product_variants_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_vehicle_compatibility product_vehicle_compatibility_chassis_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_vehicle_compatibility
    ADD CONSTRAINT product_vehicle_compatibility_chassis_id_foreign FOREIGN KEY (chassis_id) REFERENCES public.vehicle_chassis(id) ON DELETE CASCADE;


--
-- Name: product_vehicle_compatibility product_vehicle_compatibility_engine_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_vehicle_compatibility
    ADD CONSTRAINT product_vehicle_compatibility_engine_id_foreign FOREIGN KEY (engine_id) REFERENCES public.vehicle_engines(id) ON DELETE CASCADE;


--
-- Name: product_vehicle_compatibility product_vehicle_compatibility_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_vehicle_compatibility
    ADD CONSTRAINT product_vehicle_compatibility_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: product_vins product_vins_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_vins
    ADD CONSTRAINT product_vins_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: products products_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.categories(id) ON DELETE SET NULL;


--
-- Name: products products_engine_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_engine_id_foreign FOREIGN KEY (engine_id) REFERENCES public.vehicle_engines(id) ON DELETE SET NULL;


--
-- Name: products products_product_line_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_product_line_id_foreign FOREIGN KEY (product_line_id) REFERENCES public.product_lines(id) ON DELETE SET NULL;


--
-- Name: purchase_order_items purchase_order_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE RESTRICT;


--
-- Name: purchase_order_items purchase_order_items_purchase_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_purchase_order_id_foreign FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE CASCADE;


--
-- Name: purchase_order_items purchase_order_items_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE RESTRICT;


--
-- Name: purchase_order_items purchase_order_items_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE RESTRICT;


--
-- Name: purchase_order_payments purchase_order_payments_purchase_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_payments
    ADD CONSTRAINT purchase_order_payments_purchase_order_id_foreign FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE CASCADE;


--
-- Name: purchase_order_payments purchase_order_payments_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_payments
    ADD CONSTRAINT purchase_order_payments_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE RESTRICT;


--
-- Name: purchase_orders purchase_orders_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE RESTRICT;


--
-- Name: sales_order_items sales_order_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_items
    ADD CONSTRAINT sales_order_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id);


--
-- Name: sales_order_items sales_order_items_sales_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_items
    ADD CONSTRAINT sales_order_items_sales_order_id_foreign FOREIGN KEY (sales_order_id) REFERENCES public.sales_orders(id) ON DELETE CASCADE;


--
-- Name: sales_order_items sales_order_items_seller_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_items
    ADD CONSTRAINT sales_order_items_seller_id_foreign FOREIGN KEY (seller_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: sales_order_items sales_order_items_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_items
    ADD CONSTRAINT sales_order_items_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE RESTRICT;


--
-- Name: sales_order_items sales_order_items_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_items
    ADD CONSTRAINT sales_order_items_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: sales_order_payments sales_order_payments_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_payments
    ADD CONSTRAINT sales_order_payments_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: sales_order_payments sales_order_payments_sales_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_order_payments
    ADD CONSTRAINT sales_order_payments_sales_order_id_foreign FOREIGN KEY (sales_order_id) REFERENCES public.sales_orders(id) ON DELETE CASCADE;


--
-- Name: sales_orders sales_orders_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_orders
    ADD CONSTRAINT sales_orders_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: sales_orders sales_orders_pos_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_orders
    ADD CONSTRAINT sales_orders_pos_session_id_foreign FOREIGN KEY (pos_session_id) REFERENCES public.pos_sessions(id) ON DELETE SET NULL;


--
-- Name: sales_orders sales_orders_prepared_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_orders
    ADD CONSTRAINT sales_orders_prepared_by_foreign FOREIGN KEY (prepared_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: sales_quote_items sales_quote_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quote_items
    ADD CONSTRAINT sales_quote_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id);


--
-- Name: sales_quote_items sales_quote_items_sales_quote_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quote_items
    ADD CONSTRAINT sales_quote_items_sales_quote_id_foreign FOREIGN KEY (sales_quote_id) REFERENCES public.sales_quotes(id) ON DELETE CASCADE;


--
-- Name: sales_quote_items sales_quote_items_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quote_items
    ADD CONSTRAINT sales_quote_items_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: sales_quotes sales_quotes_converted_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quotes
    ADD CONSTRAINT sales_quotes_converted_order_id_foreign FOREIGN KEY (converted_order_id) REFERENCES public.sales_orders(id) ON DELETE SET NULL;


--
-- Name: sales_quotes sales_quotes_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quotes
    ADD CONSTRAINT sales_quotes_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: sales_quotes sales_quotes_pos_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quotes
    ADD CONSTRAINT sales_quotes_pos_session_id_foreign FOREIGN KEY (pos_session_id) REFERENCES public.pos_sessions(id) ON DELETE SET NULL;


--
-- Name: sales_quotes sales_quotes_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quotes
    ADD CONSTRAINT sales_quotes_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: sales_quotes sales_quotes_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_quotes
    ADD CONSTRAINT sales_quotes_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE SET NULL;


--
-- Name: sales_return_items sales_return_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_return_items
    ADD CONSTRAINT sales_return_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id);


--
-- Name: sales_return_items sales_return_items_sales_order_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_return_items
    ADD CONSTRAINT sales_return_items_sales_order_item_id_foreign FOREIGN KEY (sales_order_item_id) REFERENCES public.sales_order_items(id) ON DELETE CASCADE;


--
-- Name: sales_return_items sales_return_items_sales_return_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_return_items
    ADD CONSTRAINT sales_return_items_sales_return_id_foreign FOREIGN KEY (sales_return_id) REFERENCES public.sales_returns(id) ON DELETE CASCADE;


--
-- Name: sales_return_items sales_return_items_seller_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_return_items
    ADD CONSTRAINT sales_return_items_seller_id_foreign FOREIGN KEY (seller_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: sales_return_items sales_return_items_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_return_items
    ADD CONSTRAINT sales_return_items_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: sales_return_items sales_return_items_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_return_items
    ADD CONSTRAINT sales_return_items_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE SET NULL;


--
-- Name: sales_returns sales_returns_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_returns
    ADD CONSTRAINT sales_returns_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: sales_returns sales_returns_sales_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_returns
    ADD CONSTRAINT sales_returns_sales_order_id_foreign FOREIGN KEY (sales_order_id) REFERENCES public.sales_orders(id) ON DELETE CASCADE;


--
-- Name: sales_returns sales_returns_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_returns
    ADD CONSTRAINT sales_returns_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: sales_returns sales_returns_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sales_returns
    ADD CONSTRAINT sales_returns_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: supply_list_items supply_list_items_added_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_list_items
    ADD CONSTRAINT supply_list_items_added_by_foreign FOREIGN KEY (added_by) REFERENCES public.users(id);


--
-- Name: supply_list_items supply_list_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_list_items
    ADD CONSTRAINT supply_list_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: supply_list_items supply_list_items_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_list_items
    ADD CONSTRAINT supply_list_items_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: supply_list_items supply_list_items_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.supply_list_items
    ADD CONSTRAINT supply_list_items_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE SET NULL;


--
-- Name: user_warehouse user_warehouse_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_warehouse
    ADD CONSTRAINT user_warehouse_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_warehouse user_warehouse_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_warehouse
    ADD CONSTRAINT user_warehouse_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE CASCADE;


--
-- Name: users users_current_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_current_warehouse_id_foreign FOREIGN KEY (current_warehouse_id) REFERENCES public.warehouses(id) ON DELETE SET NULL;


--
-- Name: vehicle_chassis_model vehicle_chassis_model_chassis_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_chassis_model
    ADD CONSTRAINT vehicle_chassis_model_chassis_id_foreign FOREIGN KEY (chassis_id) REFERENCES public.vehicle_chassis(id) ON DELETE CASCADE;


--
-- Name: vehicle_chassis vehicle_chassis_model_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_chassis
    ADD CONSTRAINT vehicle_chassis_model_id_foreign FOREIGN KEY (model_id) REFERENCES public.vehicle_models(id) ON DELETE SET NULL;


--
-- Name: vehicle_chassis_model vehicle_chassis_model_model_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_chassis_model
    ADD CONSTRAINT vehicle_chassis_model_model_id_foreign FOREIGN KEY (model_id) REFERENCES public.vehicle_models(id) ON DELETE CASCADE;


--
-- Name: vehicle_engine_model vehicle_engine_model_engine_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_engine_model
    ADD CONSTRAINT vehicle_engine_model_engine_id_foreign FOREIGN KEY (engine_id) REFERENCES public.vehicle_engines(id) ON DELETE CASCADE;


--
-- Name: vehicle_engine_model vehicle_engine_model_model_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_engine_model
    ADD CONSTRAINT vehicle_engine_model_model_id_foreign FOREIGN KEY (model_id) REFERENCES public.vehicle_models(id) ON DELETE CASCADE;


--
-- Name: vehicle_engines vehicle_engines_model_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_engines
    ADD CONSTRAINT vehicle_engines_model_id_foreign FOREIGN KEY (model_id) REFERENCES public.vehicle_models(id) ON DELETE SET NULL;


--
-- Name: vehicle_models vehicle_models_make_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_models
    ADD CONSTRAINT vehicle_models_make_id_foreign FOREIGN KEY (make_id) REFERENCES public.vehicle_makes(id) ON DELETE CASCADE;


--
-- Name: vehicle_vins vehicle_vins_engine_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_vins
    ADD CONSTRAINT vehicle_vins_engine_id_foreign FOREIGN KEY (engine_id) REFERENCES public.vehicle_engines(id) ON DELETE CASCADE;


--
-- Name: vehicle_vins vehicle_vins_model_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vehicle_vins
    ADD CONSTRAINT vehicle_vins_model_id_foreign FOREIGN KEY (model_id) REFERENCES public.vehicle_models(id) ON DELETE CASCADE;


--
-- Name: warehouse_batches warehouse_batches_product_batch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouse_batches
    ADD CONSTRAINT warehouse_batches_product_batch_id_foreign FOREIGN KEY (product_batch_id) REFERENCES public.product_batches(id) ON DELETE CASCADE;


--
-- Name: warehouse_batches warehouse_batches_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.warehouse_batches
    ADD CONSTRAINT warehouse_batches_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id) ON DELETE CASCADE;


--
-- Name: work_order_items work_order_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_order_items
    ADD CONSTRAINT work_order_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id);


--
-- Name: work_order_items work_order_items_variant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_order_items
    ADD CONSTRAINT work_order_items_variant_id_foreign FOREIGN KEY (variant_id) REFERENCES public.product_variants(id) ON DELETE SET NULL;


--
-- Name: work_order_items work_order_items_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_order_items
    ADD CONSTRAINT work_order_items_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- Name: work_order_items work_order_items_work_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_order_items
    ADD CONSTRAINT work_order_items_work_order_id_foreign FOREIGN KEY (work_order_id) REFERENCES public.work_orders(id) ON DELETE CASCADE;


--
-- Name: work_orders work_orders_assigned_to_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_assigned_to_foreign FOREIGN KEY (assigned_to) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: work_orders work_orders_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.customers(id) ON DELETE SET NULL;


--
-- Name: work_orders work_orders_warehouse_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.work_orders
    ADD CONSTRAINT work_orders_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES public.warehouses(id);


--
-- PostgreSQL database dump complete
--

\unrestrict JFK3j8u1FfMg7LtteAAHclcJqsehqvbB4Wp2aPuDInV3dPZ136w86QfyofSkXpu

--
-- PostgreSQL database dump
--

\restrict qob9b1S5NMO5HQi8wYs4xfwYui7Er3B0NxoyphWEiW6pLlqYMsI4JKzgTgVerZg

-- Dumped from database version 18.1
-- Dumped by pg_dump version 18.1

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2025_08_14_170933_add_two_factor_columns_to_users_table	1
5	2026_02_11_000001_create_brands_table	1
6	2026_02_11_000002_create_suppliers_table	1
7	2026_02_11_000003_create_warehouses_table	1
8	2026_02_11_000004_create_attribute_definitions_table	1
9	2026_02_11_000005_create_product_lines_table	1
10	2026_02_11_000006_create_products_table	1
11	2026_02_11_000007_create_product_pricing_table	1
12	2026_02_11_000009_create_product_suppliers_table	1
13	2026_02_11_000010_create_product_media_table	1
14	2026_02_11_000011_create_inventory_table	1
15	2026_02_11_000012_create_oem_references_table	1
16	2026_02_11_000013_create_alternative_products_table	1
17	2026_02_11_000014_create_vehicle_makes_table	1
18	2026_02_11_000015_create_vehicle_models_table	1
19	2026_02_11_000016_create_vehicle_engines_table	1
20	2026_02_11_000017_create_product_vehicle_compatibility_table	1
21	2026_02_12_040811_create_product_inventory_table	1
22	2026_02_12_041426_create_inventory_movements_table	1
23	2026_02_12_060000_create_customers_table	1
24	2026_02_12_060001_create_customer_transactions_table	1
25	2026_02_12_081541_create_sales_orders_table	1
26	2026_02_12_081556_create_sales_order_items_table	1
27	2026_02_12_232234_create_product_batches_table	1
28	2026_02_12_232234_create_warehouse_batches_table	1
29	2026_02_12_232240_add_batch_id_to_inventory_movements_table	1
30	2026_02_12_233054_add_batch_tracking_to_products_table	1
31	2026_02_12_999999_remove_user_id_foreign_key_from_sales_orders	1
32	2026_02_13_060029_create_product_attributes_table	1
33	2026_02_13_100000_add_fields_to_suppliers_table	1
34	2026_02_13_100001_create_purchase_orders_table	1
35	2026_02_13_100002_create_purchase_order_items_table	1
36	2026_02_13_190909_create_settings_table	1
37	2026_02_13_200000_create_product_variants_table	1
38	2026_02_13_200001_add_variant_id_to_product_pricing	1
39	2026_02_13_200002_add_variant_id_to_product_inventory	1
40	2026_02_13_200003_add_variant_id_to_order_items	1
41	2026_02_13_200004_migrate_existing_products_to_variants	1
42	2026_02_13_200005_update_products_structure	1
43	2026_02_13_224610_create_categories_table	1
44	2026_02_13_224648_add_category_id_to_products_table	1
45	2026_02_13_230720_add_variant_id_to_product_suppliers_table	1
46	2026_02_13_231300_migrate_inventory_to_variants	1
47	2026_02_14_030833_add_image_url_to_products_table	1
48	2026_02_14_074701_add_variant_id_to_inventory_movements	1
49	2026_02_14_074702_change_reference_type_to_string_in_inventory_movements	1
50	2026_02_15_043000_fix_product_inventory_unique_constraint	1
51	2026_02_16_020014_add_current_warehouse_id_to_users_table	1
52	2026_02_16_032100_add_status_location_capacity_notes_to_warehouses_table	1
53	2026_02_17_210454_add_website_to_brands_table	1
54	2026_02_18_023951_create_product_vins_table	1
55	2026_02_18_025512_add_reason_to_inventory_movements_table	1
56	2026_02_19_061158_add_variant_id_to_product_attributes_table	1
57	2026_02_19_183327_create_vehicle_vins_table	1
58	2026_02_19_235038_create_pos_sessions_table	1
59	2026_02_19_235303_add_pos_session_id_to_sales_orders_table	1
60	2026_02_20_022700_create_sales_order_payments_table	1
61	2026_02_20_032643_create_purchase_order_payments_table	1
62	2026_02_20_204119_add_payment_status_to_purchase_orders_table	1
63	2026_02_21_015626_add_role_to_users_table	1
64	2026_02_21_222718_create_activity_log_table	1
65	2026_02_21_222719_add_event_column_to_activity_log_table	1
66	2026_02_21_222720_add_batch_uuid_column_to_activity_log_table	1
67	2026_02_21_230000_add_closing_details_to_pos_sessions	1
68	2026_02_22_000001_create_sales_quotes_tables	1
69	2026_02_22_035500_allow_multiple_pricing_per_product	1
70	2026_02_22_220000_add_warehouse_id_to_pos_sessions_table	1
71	2026_02_24_000001_create_sales_returns_table	1
72	2026_02_24_000002_add_unit_cost_to_sales_order_items	1
73	2026_02_24_022200_add_seller_id_to_sales_order_items_table	1
74	2026_02_26_000001_create_user_warehouse_table	1
75	2026_02_26_000002_create_inventory_transfers_tables	1
76	2026_02_28_200000_add_tax_type_to_product_pricing	1
77	2026_03_01_005215_add_tax_type_to_product_pricing_table	1
78	2026_03_01_020000_add_default_attributes_to_categories	1
79	2026_03_01_200000_add_audit_logs_to_sales_orders	1
80	2026_03_01_233354_alter_vin_columns_length	1
81	2026_03_02_000001_add_notes_to_sales_order_items	1
82	2026_03_02_000002_add_refund_method_to_sales_returns	1
83	2026_03_03_000001_add_user_id_to_sales_return_items	1
84	2026_03_03_011855_add_username_to_users_table	1
85	2026_03_03_200000_create_cash_movements_table	1
86	2026_03_05_022929_add_store_settings_seeder	1
87	2026_03_05_041431_add_notes_to_product_batches_table	1
88	2026_03_08_000001_add_generation_to_vehicle_models	1
89	2026_03_08_000002_add_is_universal_to_products	1
90	2026_03_08_053915_add_generation_to_vehicle_models	1
91	2026_03_08_200000_increase_upc_length_on_product_variants_table	1
92	2026_03_11_000001_remove_unique_from_sku_columns	1
93	2026_03_11_000002_add_alternate_code_to_products	1
94	2026_03_11_000003_add_bin_location_to_products	1
95	2026_03_11_000004_add_pricing_formula_to_settings_and_categories	1
96	2026_03_11_000005_add_workflow_fields_to_sales_quotes	1
97	2026_03_11_074438_add_pricing_formula_to_products_table	1
98	2026_03_11_100000_drop_bin_location_from_products	1
99	2026_03_11_174907_add_pricing_formula_to_product_pricing_table	1
100	2026_03_12_002142_remove_unique_from_upc_column	1
101	2026_03_12_011851_drop_sku_unique_from_product_variants_table	1
102	2026_03_17_000001_create_work_orders_table	1
103	2026_03_17_120000_add_internal_notes_to_sales_quotes_and_orders	1
104	2026_03_21_020809_add_sent_to_cashier_at_to_sales_quotes	1
105	2026_03_22_032736_create_personal_access_tokens_table	1
106	2026_03_23_015848_add_store_credit_to_sales_order_payments_method_enum	1
107	2026_03_23_025900_add_store_credit_used_to_customer_transactions_type_enum	1
108	2026_03_23_191834_add_seller_id_to_sales_return_items_table	1
109	2026_03_24_024129_fix_historical_credit_payment_status	1
110	2026_03_25_000001_add_default_margin_percent_to_categories	2
111	2026_03_25_000002_create_supply_list_items_table	3
112	2026_03_25_000003_add_stock_non_negative_check	4
113	2026_03_25_000004_add_applies_itbm_to_categories	4
114	2026_03_26_000001_add_engine_id_to_products	5
115	2026_03_26_100001_create_payment_methods_table	6
116	2026_03_27_021630_add_warehouse_queue_to_sales_orders	7
117	2026_03_27_050000_add_supplier_id_to_purchase_order_items_table	8
118	2026_03_27_050001_make_supplier_id_nullable_on_purchase_orders_table	8
119	2026_03_27_050002_add_status_to_purchase_order_items_table	8
120	2026_03_27_050003_migrate_supplier_id_to_purchase_order_items	8
121	2026_03_27_060000_make_model_id_nullable_on_vehicle_engines	9
122	2026_03_27_065000_add_aspiration_to_vehicle_engines	10
123	2026_03_27_070000_add_itbm_percent_to_categories	11
124	2026_03_28_000001_add_pricing_fields_to_supply_list_items	12
125	2026_03_30_002200_allow_dynamic_payment_methods	13
126	2026_03_30_180000_add_default_cost_to_categories_table	14
127	2026_04_07_000001_create_vehicle_chassis_table	15
128	2026_04_07_000002_add_chassis_and_flags_to_product_vehicle_compatibility	15
129	2026_04_10_201414_add_system_id_to_products_table	16
130	2026_04_10_201500_add_prefix_to_brands_table	16
131	2026_04_10_201600_add_system_id_to_product_variants_table	16
132	2026_04_10_215951_add_supplier_id_to_inventory_movements_table	17
133	2026_04_10_220624_add_units_per_package_to_purchase_order_items_table	17
134	2026_04_10_221944_create_pricing_formulas_table	18
135	2026_04_11_000001_add_custom_line_support_to_sales_items	19
136	2026_04_13_120000_add_min_suggested_price_to_categories_table	19
137	2026_04_14_120000_create_brand_supplier_table	19
138	2026_04_14_130000_add_model_id_to_vehicle_vins_table	19
139	2026_04_15_180000_add_stock_added_qty_to_supply_list_items_table	20
140	2026_04_15_180100_add_pre_stock_applied_qty_to_purchase_order_items_table	20
141	2026_04_16_000001_create_vehicle_chassis_model_table	21
142	2026_04_17_000001_create_vehicle_engine_model_table	22
143	2026_04_17_090000_add_cost_and_sale_tax_rate_to_product_pricing_table	22
144	2026_04_17_120000_fix_vehicle_vins_vin_length_to_40	23
145	2026_04_17_130000_add_cost_breakdown_fields_to_product_pricing_table	24
146	2026_04_25_000001_create_product_extra_categories_table	25
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 146, true);


--
-- PostgreSQL database dump complete
--

\unrestrict qob9b1S5NMO5HQi8wYs4xfwYui7Er3B0NxoyphWEiW6pLlqYMsI4JKzgTgVerZg

