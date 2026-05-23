create database rms;

use rms;

-- Table for the condition_details for admin access
create table condition_details (
    key_id int auto_increment primary key,
    --supper admin permission
    is_sadm boolean default false,
    --rms platform access persmission
    can_access_dts boolean default false,
    can_access_archv boolean default false,
    can_access_dcs boolean default false,
    --dts modifier permission
    can_modify_docflow boolean default false,
    can_modify_accountlist boolean default false,
    --account modifier permission
    can_modify_pass boolean default false,
    can_modify_user boolean default false,
    --archv modifier permission
    can_view_all_list boolean default false,
    can_view_all_archive boolean default false,
    -- others permission will be added here
);
create table condition_key (
    id int auto_increment primary key,
    -- the name of the condition key
    key_name varchar(255) not null,
    -- the description of the condition 
    key_description text,
    modifier_key int,
    foreign key (modifier_key) references condition_details(key_id)
);

--add one default data
insert into condition_defaults (key_id) values (1);
insert into condition_key (key_name, key_description, modifier_key) values ('Default', 'Default condition key with lowest permission', 1);

create table account (
    id int unique auto_increment primary key,
    username varchar(255) not null unique,
    password varchar(255) not null,
    --for role management, default is 1 which is the lowest permission
    account_status int default 1,
    -- for account management, default is true which means the account is active for use
    account_active boolean default true,
    date_created datetime default current_timestamp,
    date_updated datetime default current_timestamp on update current_timestamp,
    foreign key (account_status) references condition_key(id)
);

--Offices
create table office (
    id int auto_increment primary key,
    office_name varchar(255) not null unique,
    office_code varchar(50) not null unique,
    is_active boolean default true
);

create table account_details (
    account_id int,
    first_name varchar(255) not null,
    last_name varchar(255) not null,
    middle_name varchar(255),
    office_id int,
    email varchar(255) not null unique,
    contact_number varchar(25),
    is_currently_online boolean default false,
    last_online_time datetime,
    foreign key (account_id) references account(id),
    foreign key (office_id) references office(id)
)