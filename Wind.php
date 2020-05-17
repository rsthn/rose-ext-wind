<?php
/*
**	Rose\Ext\Wind
**
**	Copyright (c) 2019-2020, RedStar Technologies, All rights reserved.
**	https://rsthn.com/
**
**	THIS LIBRARY IS PROVIDED BY REDSTAR TECHNOLOGIES "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
**	INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A 
**	PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL REDSTAR TECHNOLOGIES BE LIABLE FOR ANY
**	DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT 
**	NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; 
**	OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, 
**	STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE
**	USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

namespace Rose\Ext;

use Rose\Errors\FalseError;

use Rose\IO\Directory;
use Rose\IO\Path;
use Rose\IO\File;

use Rose\Gateway;
use Rose\Regex;
use Rose\Text;
use Rose\DateTime;
use Rose\Expr;
use Rose\Arry;
use Rose\Map;

use Rose\Resources;
use Rose\Session;
use Rose\Strings;
use Rose\Configuration;

use Rose\Ext\Wind\SubReturn;

/*
**	Wind extension.
*/

class WindProxy
{
	public function main() {
		Wind::main();
	}
};

class Wind
{
	private static $base;
	private static $cache;
	private static $data;

	private static $contentFlushed;
	private static $contentType;
	private static $response;

	private static $callStack;

	public const R_OK 						= 200;
	public const R_FUNCTION_NOT_FOUND 		= 400;
	public const R_DATABASE_ERROR			= 401;
	public const R_FORBIDDEN 				= 402;
	public const R_PRIVILEGE_REQUIRED		= 403;
	public const R_NOT_FOUND				= 404;

	public const R_VALIDATION_ERROR			= 407;
	public const R_NOT_AUTHENTICATED		= 408;
	public const R_CUSTOM_ERROR				= 409;
	public const R_INVALID_DATA				= 410;

	public static function init()
	{
		Gateway::registerService ('wind', new WindProxy());

		self::$base = 'resources/wind';
		self::$cache = 'resources/windc';

		self::$data = new Map();
		self::$callStack = new Arry();

		if (!Path::exists(self::$cache))
			Directory::create(self::$cache);

		self::$contentFlushed = false;
		self::$contentType = null;
	}

	public static function reply ($response)
	{
		if (self::$contentFlushed)
			Gateway::exit();

		if (is_array($response))
			$response = new Map ($response);

		if (\Rose\typeOf($response) == 'Rose\\Map' || \Rose\typeOf($response) == 'Rose\Arry')
		{
			if (self::$contentType == null)
				self::$contentType = 'Content-Type: application/json; charset=utf-8';

			if (\Rose\typeOf($response) == 'Rose\Arry')
			{
				$response = new Map([ 'response' => Wind::R_OK, 'data' => $response ], false);
			}
			else
			{
				if (!$response->has('response'))
				{
					$tmp = new Map([ 'response' => Wind::R_OK ]);
					$tmp->merge ($response, true);
					$response = $tmp;
				}
			}
		}
		else if (is_string($response) && strlen($response) != 0)
		{
			if (self::$contentType == null)
				self::$contentType = 'Content-Type: text/plain; charset=utf-8';
		}
		else
		{
			$response = $response ? (string)$response : null;
		}

		self::$response = $response;

		if ($response != null && self::$data->internal_call == 0)
		{
			Gateway::header(self::$contentType);
			echo (string)$response;
		}

		if (self::$data->internal_call)
			throw new SubReturn();

		Gateway::exit();
	}

	public static function process ($path)
	{
		if ($path[0] == '@')
			$path = self::$callStack->get(self::$callStack->length-1)[0].$path;

		$path1 = Path::append(self::$base, Text::replace('.', '/', $path) . '.fn');
		$path2 = Path::append(self::$cache, $path.'.fn');

		self::$response = null;

		if (Path::exists($path2) && Path::exists($path1) && File::mtime($path2, true) == File::mtime($path1, true))
		{
			$expr = unserialize(File::getContents($path2));
		}
		else if (Path::exists($path1))
		{
			$expr = Expr::parse(File::getContents($path1));

			for ($i = 0; $i < $expr->length; $i++)
			{
				if ($expr->get($i)->type != 'template')
				{
					$expr->remove($i);
					$i--;
				}
			}

			File::setContents($path2, serialize($expr));
			File::touch($path2, File::mtime($path1, true));
		}
		else
			self::reply ([ 'response' => self::R_FUNCTION_NOT_FOUND ]);

		$tmp = Text::split('.', $path);
		$tmp->pop();
		$tmp = $tmp->join('.').'.';

		self::$callStack->push([ $tmp, $path ]);

		$response = Expr::expand($expr, self::$data, 'last');

		self::$callStack->pop();

		if ($response != null)
			self::reply ($response);
	}

	public static function main ()
	{
		$gateway = Gateway::getInstance();
		$params = $gateway->requestParams;

		self::$data->internal_call = 0;

		$f = Regex::_extract ('/[#A-Za-z0-9.,_:|-]+/', $params->f);
		if (!$f) self::reply ([ 'response' => self::R_FUNCTION_NOT_FOUND ]);

		self::process($f);
	}

	public static function header ($args, $parts, $data)
	{
		Gateway::header($args->get(1));
		return null;
	}

	public static function contentType ($args, $parts, $data)
	{
		self::$contentType = 'Content-Type: ' . $args->get(1);
		return null;
	}

	public static function return ($args, $parts, $data)
	{
		self::reply ($args->get(1));
	}

	public static function stop ($args, $parts, $data)
	{
		Gateway::exit();
	}

	public static function _echo ($parts, $data)
	{
		if (!self::$contentFlushed)
		{
			Gateway::header(self::$contentType ? self::$contentType : 'Content-Type: text/plain; charset=utf-8');
			self::$contentFlushed = true;
		}

		for ($i = 1; $i < $parts->length(); $i++)
			echo (Expr::expand($parts->get($i), $data, 'arg') . ' ');

		echo "\n";

		return null;
	}

	public static function _trace ($parts, $data)
	{
		$s = '';

		for ($i = 1; $i < $parts->length(); $i++)
			$s .= ' ' . Expr::expand($parts->get($i), $data, 'arg');

		if ($s != '')
			\Rose\trace(Text::substring($s, 1));

		return null;
	}

	public static function call ($args, $parts, $data)
	{
		self::$data->internal_call = 1 + self::$data->internal_call;

		try {
			self::process($args->get(1));
		}
		catch (SubReturn $e)
		{
			$response = self::$response;
		}

		self::$data->internal_call = self::$data->internal_call - 1;

		if (\Rose\typeOf($response) == 'Rose\Map' && $response->response != 200)
			self::reply($response);

		return $response;
	}
};

/* ****************************************************************************** */
Expr::register('Session', function ($args) { return Session::$data; });
Expr::register('Configuration', function ($args) { return Configuration::getInstance(); });
Expr::register('Strings', function ($args) { return Strings::getInstance(); });
Expr::register('Resources', function ($args) { return Resources::getInstance(); });
Expr::register('Gateway', function ($args) { return Gateway::getInstance(); });

Expr::register('Now', function ($args) { return new DateTime(); });
Expr::register('Request', function ($args) { return Gateway::getInstance()->requestParams; });

Expr::register('header', function(...$args) { return Wind::header(...$args); });
Expr::register('contentType', function(...$args) { return Wind::contentType(...$args); });
Expr::register('return', function(...$args) { return Wind::return(...$args); });
Expr::register('stop', function(...$args) { return Wind::stop(...$args); });
Expr::register('return', function(...$args) { return Wind::return(...$args); });
Expr::register('_echo', function(...$args) { return Wind::_echo(...$args); });
Expr::register('_trace', function(...$args) { return Wind::_trace(...$args); });
Expr::register('call', function(...$args) { return Wind::call(...$args); });

/* ****************************************************************************** */
Wind::init();
