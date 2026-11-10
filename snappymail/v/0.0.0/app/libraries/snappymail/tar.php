<?php

namespace SnappyMail;

class TAR
{
	public
		$alias,
		$filename;

	const dec = array(
		'mode', 'uid', 'gid', 'size', 'mtime', 'crc', 'type'
	);

	function __construct(string $filename, int $flags = 0, ?string $alias = null)
	{
		$this->filename = $filename;
		$this->alias = $alias ?: $filename;
	}

	public function extractTo(string $directory, array|string|null $files = null, bool $overwrite = false) : bool
	{
		$fp = \gzopen($this->filename, 'rb');
		if (!$fp) {
			return false;
		}

		if ($files) {
			$files = '#^(' . \implode('|', \array_map('preg_quote', \is_array($files) ? $files : [$files])) . ')#u';
		}

		\clearstatcache(true);
		while (!\gzeof($fp)) {
			$data = \gzread($fp, 512);
			if (\gzeof($fp) || !\trim($data)) {
				break;
			}
			if (\strlen($data) !== 512) {
				\trigger_error("{$this->alias} invalid header at offset " . \gztell($fp));
				return false;
			}
			$header = \unpack('a100filename/a8mode/a8uid/a8gid/a12size/a12mtime/a8crc/a1type/a100linkname/a6magic/a2version/a32uname/a32gname/a8devmajor/a8devminor/a155path', $data);
			if (!\strlen(\trim($header['filename']))) {
				\trigger_error("{$this->alias} invalid entry filename at offset " . \gztell($fp));
				return false;
			}
			foreach ($header as $k => $v) {
				$header[$k] = \in_array($k, self::dec) ? \octdec(\trim($v)) : \trim($v);
			}
			if ($header['crc'] > 0) {
				$crc = 0;
				for ($i = 0;   $i < 148; ++$i) { $crc += \ord(\substr($data, $i, 1)); }
				for ($i = 148; $i < 156; ++$i) { $crc += \ord(' '); }
				for ($i = 156; $i < 512; ++$i) { $crc += \ord(\substr($data, $i, 1)); }
				if ($header['crc'] !== $crc) {
					\trigger_error("{$this->alias} checksum of '{$header['filename']}' incorrect");
					return false;
				}
			}
			if (\preg_match('#(^|/)PaxHeader/#', $header['filename'])) {
			} else if (\substr($header['filename'], -1) !== '/') {
				$filename = ($header['path'] ? $header['path'] . '/' : '') . $header['filename'];
				if ($files && !\preg_match($files, $filename)) {
					continue;
				}
				$filename = $directory . '/' . $filename;
				if (\is_file($filename) && !$overwrite) {
					continue;
				}
				$dir = \dirname($filename);
				if (!\is_dir($dir) && !\mkdir($dir, 0777, true)) {
					return false;
				}
				$target = \fopen($filename, 'wb');
				if (!$target) {
					return false;
				}
				$bytes = $header['size'];
				$blocksize = \ceil($bytes / 512) * 512;
				$blocks = \ceil($blocksize / 8192);
				while ($blocks--) {
					$data = \gzread($fp, \min($blocksize, 8192));
					$length = \strlen($data);
					if ($bytes < $length) {
						$data = \substr($data, 0, $bytes);
					}
					\fwrite($target, $data);
					$bytes -= $length;
					$blocksize -= $length;
				}
				\fclose($target);
				\chmod($filename, $header['mode']);
			} else {
				// Create directory
//				\gzread($fp, $blocksize);
			}
		}
		\gzclose($fp);
		return true;
	}

	const
		TYPE_FILE = 0,
		TYPE_LINK = 2,
		TYPE_DIR  = 5;

	protected
		$gzip = array('w' => null, 'h' => null, 'l' => 0),
		$stream = null,
		$stream_info = null;

/*
	const
		OVERWRITE = 1,
		CREATE    = 2,
		RDONLY    = 4,
		EXCL      = 8,
		CHECKCONS = 16;
	public function open(string $filename, int $flags = 0): bool|int
*/
	public function open(): void
	{
		if ($this->stream) {
			return;
		}
		$this->stream = \fopen($this->filename, 'wb');
		if (\str_ends_with($this->filename, '.gz')) {
			// Write gzip header, see http://www.zlib.org/rfc-gzip.html#member-format
			if (!\fwrite($this->stream, "\x1F\x8B\x08\x00".\pack('V', \time())."\0\x03", 10)) {
				throw new \Exception('Failed to write to stream');
			}
			// Start compression
			$this->gzip['w'] = \stream_filter_append($this->stream, 'zlib.deflate', STREAM_FILTER_WRITE, array(
				'level' => 9,
//				'window' => 32768,
				'memory' => 9,
			));
			// Start CRC32 hashing
			$this->gzip['h'] = \hash_init('crc32b');
		}
		$this->stream_info = new \SplFileInfo($this->filename);
	}

	function __destruct()
	{
		$this->close();
	}

	public function close(): bool
	{
		if (!$this->stream) {
			return false;
		}
		// Write tar footer
		$this->write(\pack('a1024', ''));
		// Stop compression
		if ($this->gzip['w']) {
			\stream_filter_remove($this->gzip['w']);
			// hash_final is a string, not an integer
			$crc = \hash_final($this->gzip['h'], 1);
			// write the little endian CRC32 and uncompressed file size
			\fwrite($this->stream, $crc[3].$crc[2].$crc[1].$crc[0].\pack('V', $this->gzip['l']), 8);
		}
		\fclose($this->stream);
		$this->stream = $this->stream_info = null;
		return true;
	}

	public function addFile(string $name, ?string $localName = null, ?\SplFileInfo $fileinfo = null): void
	{
		$this->open();
		if (!$fileinfo) {
			$fileinfo = new \SplFileInfo($name);
		}
		if (!$localName) {
			$localName = $name;
		}
		if ($fileinfo->isLink()) {
			$stat = \lstat($fileinfo);
			$target = $fileinfo->getLinkTarget();
			if (\dirname($fileinfo->getPathname()) === \dirname($fileinfo->getRealPath())) {
				$target = \basename($target);
			}
			$this->writeEntryHeader(
				$localName,
				static::TYPE_LINK,
				0,
				$stat['mode'],
				$target
			);
		} else if ($fileinfo->isDir()) {
			$this->writeEntryHeader(
				\rtrim($localName, '/') . '/',
				static::TYPE_DIR,
				0,
				$fileinfo->getPerms()
			);
		} else {
			$file = $fileinfo->openFile('rb');
			$this->writeEntryHeader(
				$localName,
				static::TYPE_FILE,
				$file->getSize(),
				$fileinfo->getPerms()
			);
			while (!$file->eof()) {
				$data = $file->fread(4096);
				if (false === $data || '' === $data) {
					break;
				}
				$this->write($data);
			}
			if ($l = $file->ftell() % 512) {
				$l = 512 - $l;
				$this->write(\pack("a{$l}", ''));
			}
			unset($file);
		}
	}

	public function addFromString(string $name, string $contents): void
	{
		$this->open();
		$this->writeEntryHeader(
			$name,
			static::TYPE_FILE,
			\strlen($contents),
			$this->stream_info->getPerms()
		);
		$this->write($contents);
		if ($l = \strlen($contents) % 512) {
			$l = 512 - $l;
			$this->write(\pack("a{$l}", ''));
		}
	}

	public function buildFromDirectory(string $dir, string $pattern = ''): array
	{
		$this->open();
		\clearstatcache();
		$dir = \rtrim($dir,'\\/') . '/';
		$dirl = \strlen($dir);
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS /*| \FilesystemIterator::FOLLOW_SYMLINKS*/),
			\RecursiveIteratorIterator::SELF_FIRST,
			\RecursiveIteratorIterator::CATCH_GET_CHILD);
		$ignore_paths = array();
		foreach ($iterator as $name => $fileinfo) {
			if ($fileinfo->isDir() && \is_file("{$fileinfo}/CACHEDIR.TAG")) {
				// like: tar --exclude-caches -czf file.tgz *
				$ignore_paths[] = \preg_quote($name . '/', '#');
				continue;
			}
			if ($ignore_paths && \preg_match('#'.\implode('|',$ignore_paths).'#', $name)) {
				continue;
			}
			if (!$pattern || \preg_match($pattern, $name)) {
				$name = \substr($name, $dirl);
				$this->addFile($name, null, $fileinfo);
			}
		}
		return [];
	}

	protected function write(string $string): int
	{
		$length = \strlen($string);
		$written = 0;
		while ($written < $length) {
			$bytes = \fwrite($this->stream, $written ? \substr($string, $written) : $string);
			if (!$bytes) {
				return $written ?: false;
			}
			$written += $bytes;
		}

		if ($this->gzip['h']) {
			$this->gzip['l'] += $length;
			\hash_update($this->gzip['h'], $string);
		}

		return $written;
	}

	protected function writeEntryHeader(string $name, int $type, int $size,
		int $perm = 0, string $link = '', string $prefix  = ''
	): void
	{
		// handle long filename length
		$paxdata = $paxname = '';
		if (100 < \strlen($link)) {
			$length = \strlen($link) + 11;
			$length += \strlen($length);
			$paxdata = "{$length} linkpath={$link}\n";
			$link = '././@LongSymLink';
		}
		/* Add?
		$paxdata .= "30 mtime=" . $this->stream_info->getMTime() . ".000000000\n"
		$paxdata .= "30 ctime=" . $this->stream_info->getCTime() . ".000000000\n"
		*/
		$l = \strlen($name);
		if (($paxdata?90:100) < $l) {
			// split into name and prefix
			$p = \strpos($name, '/', \max(0, $l - 90));
			if ($p && $p < $l-1) {
				$file = \substr($name, $p+1);
				$prefix = \substr($name, 0, $p);
				$paxname = \preg_replace('#(^|/)([^/]+)$#', '$1PaxHeader/$2', $file);
			} else {
				$file = \basename($name);
				if (static::TYPE_DIR === $type) {
					$file .= '/';
				}
				$prefix = \dirname($name);
				$paxname = 'PaxHeader/' . $file;
			}
			if (100 < \strlen($file) || 155 < \strlen($prefix)) {
				// POSIX.1-2001/pax
				$length = $l + 7;
				$length += \strlen($length);
				$paxdata = "{$length} path={$name}\n{$paxdata}";
				if (static::TYPE_DIR === $type) {
					$name = \substr($file, 0, 98) . '/';
				} else {
					$name = \substr($file, 0, 99);
				}
			} else {
				// POSIX ustar
				$name = $file;
			}
			$paxname = \substr($paxname, 0, 98);
		}
		if ($paxdata) {
			$this->writeUStarEntryHeader(
				$paxname ?: \preg_replace('#(^|/)([^/]+)$#', '$1PaxHeader/$2', $name),
				'x',
				\strlen($paxdata),
				$perm,
				'',
				$prefix
			);
			$l = 512 * \ceil(\strlen($paxdata) / 512);
			$this->write(\pack("a{$l}", $paxdata));
		}

		$this->writeUStarEntryHeader($name, $type, $size, $perm, $link, $prefix);
	}

	// Writes Pre-POSIX.1-1988 (i.e. v7) and POSIX UStar headers
	protected function writeUStarEntryHeader(string $name, int $type, int $size,
		int $perm = 0, string $link = '', string $prefix  = ''
	): void
	{
		$data = \pack('a100a8a8a8a12a12',
			$name,
			\sprintf('%07u', \substr(\decoct($perm),-3)),
			'',
			'',
			\sprintf('%011u', \decoct($size)),
			\sprintf('%011u', \decoct($this->stream_info->getMTime()))
		)
		// When calculating the checksum, the chksum field is treated as if it were filled with spaces (ASCII 32).
		// That's why add 8x32 = 256
		. '        '
		. \pack('a1a100a6a2a32a32a8a8a155a12', $type, $link, 'ustar', '00', '', '', '', '', $prefix, '');

		$i = 512; // \strlen($data);
		$checksum = 0;
		while ($i--) {
			$checksum += \ord($data[$i]);
		}

		$data = \substr_replace($data, \sprintf('%07u', \decoct($checksum)), 148, 7);
		$this->write($data);
	}
}
