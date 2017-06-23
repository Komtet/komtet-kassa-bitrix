create table if not exists komtet_kassa_reports
(
    id int not null auto_increment,
    order_id int not null,
    state tinyint default 0,
    error_description varchar(255) not null default '',
    primary key (id)
)
engine = MyISAM
default character set = utf8;
