<?php

namespace PDO;

// PHP < 8.4
if (\class_exists('PDO', false) && !\class_exists('PDO\\Mysql', false)) {

	trait Mysql_trait
	{
		const
			ATTR_USE_BUFFERED_QUERY = \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,
			ATTR_LOCAL_INFILE = \PDO::MYSQL_ATTR_LOCAL_INFILE,
			ATTR_INIT_COMMAND = \PDO::MYSQL_ATTR_INIT_COMMAND,
			ATTR_COMPRESS = \PDO::MYSQL_ATTR_COMPRESS,
			ATTR_DIRECT_QUERY = \PDO::MYSQL_ATTR_DIRECT_QUERY,
			ATTR_FOUND_ROWS = \PDO::MYSQL_ATTR_FOUND_ROWS,
			ATTR_IGNORE_SPACE = \PDO::MYSQL_ATTR_IGNORE_SPACE,
			ATTR_SSL_KEY = \PDO::MYSQL_ATTR_SSL_KEY,
			ATTR_SSL_CERT = \PDO::MYSQL_ATTR_SSL_CERT,
			ATTR_SSL_CA = \PDO::MYSQL_ATTR_SSL_CA,
			ATTR_SSL_CAPATH = \PDO::MYSQL_ATTR_SSL_CAPATH,
			ATTR_SSL_CIPHER = \PDO::MYSQL_ATTR_SSL_CIPHER,
			ATTR_SERVER_PUBLIC_KEY = \PDO::MYSQL_ATTR_SERVER_PUBLIC_KEY,
			ATTR_MULTI_STATEMENTS = \PDO::MYSQL_ATTR_MULTI_STATEMENTS,
			ATTR_LOCAL_INFILE_DIRECTORY = \PDO::MYSQL_ATTR_LOCAL_INFILE_DIRECTORY;

		public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
		{
			parent::__construct($dsn, $username, $password, $options);

			$driver = $this->getAttribute(\PDO::ATTR_DRIVER_NAME);
			if ('mysql' !== $driver) {
				throw new \PDOException(\sprintf('Pdo\\Mysql::__construct() cannot be used for connecting to the "%s" driver', $driver));
			}
		}

		public static function connect(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null): self
		{
			try {
				return new self($dsn, $username, $password, $options);
			} catch (\PDOException $e) {
				throw \str_contains($e->getMessage(), 'Mysql::__construct')
					? new \PDOException(\str_replace('__construct', 'connect', $e->getMessage()))
					: $e;
			}
		}
	}

	// https://github.com/the-djmaze/snappymail/issues/1205

	// non-mysqlnd; see https://www.php.net/class.pdo-mysql#pdo-mysql.constants
	if (\defined('PDO::MYSQL_ATTR_MAX_BUFFER_SIZE') && \defined('PDO::MYSQL_ATTR_READ_DEFAULT_FILE') && \defined('PDO::MYSQL_ATTR_READ_DEFAULT_GROUP')) {
		class Mysql extends \PDO
		{
			use Mysql_trait;
			const
				ATTR_READ_DEFAULT_FILE = \PDO::MYSQL_ATTR_READ_DEFAULT_FILE,
				ATTR_READ_DEFAULT_GROUP = \PDO::MYSQL_ATTR_READ_DEFAULT_GROUP,
				ATTR_MAX_BUFFER_SIZE = \PDO::MYSQL_ATTR_MAX_BUFFER_SIZE;
		}
	}
	// mysqlnd with ATTR_SSL_VERIFY_SERVER_CERT
	else if (\defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
		class Mysql extends \PDO
		{
			use Mysql_trait;
			const ATTR_SSL_VERIFY_SERVER_CERT = \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
		}
	}
	// mysqlnd without ATTR_SSL_VERIFY_SERVER_CERT
	else {
		class Mysql extends \PDO
		{
			use Mysql_trait;
		}
	}

}
