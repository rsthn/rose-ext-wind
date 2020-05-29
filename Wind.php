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
use Rose\Errors\Error;

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
use Rose\Math;

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

	private static $multiResponseMode;
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
		self::$cache = 'resources/.wind.cache';

		if (!Path::exists(self::$cache))
			Directory::create(self::$cache);

		self::$callStack = new Arry();
	
		self::$contentFlushed = false;
		self::$contentType = null;
		self::$multiResponseMode = 0;
	}

	public static function reply ($response)
	{
		if (self::$contentFlushed)
			Gateway::exit();

		if (is_array($response))
			$response = new Map ($response);

		if (\Rose\typeOf($response) == 'Rose\\Map' || \Rose\typeOf($response) == 'Rose\Arry')
		{
			if (self::$contentType == null && self::$data->internal_call == 0)
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
			if (self::$contentType == null && self::$data->internal_call == 0)
				self::$contentType = 'Content-Type: text/plain; charset=utf-8';
		}
		else
		{
			$response = $response ? (string)$response : null;
		}

		self::$response = $response;

		if (self::$multiResponseMode && self::$data->internal_call == 0)
			throw new FalseError();

		if ($response != null && self::$data->internal_call == 0)
		{
			Gateway::header(self::$contentType);
			echo (string)$response;
		}

		if (self::$data->internal_call != 0)
			throw new SubReturn();

		Gateway::exit();
	}

	public static function process ($path, $resetContext)
	{
		if ($resetContext)
		{
			self::$data = new Map();
			self::$data->internal_call = 0;
		}

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

		if ($params->rpkg != null)
		{
			$requests = Text::split (';', $params->rpkg);

			self::$multiResponseMode = 1;

			$r = new Map ();
			$n = 0;

			$originalParams = $params;

			foreach ($requests->__nativeArray as $i)
			{
				$i = Text::trim($i);
				if (!$i) continue;

				$i = Text::split (',', $i);
				if ($i->length != 2) continue;

				try {
					$gateway->requestParams->clear()->merge($originalParams, true);
					parse_str(base64_decode($i->get(1)), $requestParams);
					$gateway->requestParams->__nativeArray = array_merge($gateway->requestParams->__nativeArray, $requestParams);
				}
				catch (\Exception $e) {
					\Rose\trace('Error: '.$e->getMessage());
					continue;
				}

				if (++$n > 256) break;

				try {
					self::process($gateway->requestParams->f, true);
				}
				catch (FalseError $e) {
				}

				$r->set($i->get(0), self::$response);
			}

			self::$multiResponseMode = 0;
			self::reply($r);
		}

		$f = Regex::_extract ('/[#A-Za-z0-9.,_:|-]+/', $params->f);
		if (!$f) self::reply ([ 'response' => self::R_FUNCTION_NOT_FOUND ]);

		self::process($f, true);
	}

	/**
	**	header <header-line>
	*/
	public static function header ($args, $parts, $data)
	{
		Gateway::header($args->get(1));
		return null;
	}

	/**
	**	contentType <mime>
	*/
	public static function contentType ($args, $parts, $data)
	{
		self::$contentType = 'Content-Type: ' . $args->get(1);
		return null;
	}

	/**
	**	return <data>
	*/
	public static function return ($args, $parts, $data)
	{
		self::reply ($args->get(1));
	}

	/**
	**	stop
	*/
	public static function stop ($args, $parts, $data)
	{
		Gateway::exit();
	}

	/**
	**	error <message>
	*/
	public static function error ($args, $parts, $data)
	{
		throw new Error ($args->get(1));
	}

	/**
	**	echo <message> [<message>...]
	*/
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

	/**
	**	trace <message> [<message>...]
	*/
	public static function _trace ($parts, $data)
	{
		$s = '';

		for ($i = 1; $i < $parts->length(); $i++)
			$s .= ' ' . Expr::expand($parts->get($i), $data, 'arg');

		if ($s != '')
			\Rose\trace(Text::substring($s, 1));

		return null;
	}

	/**
	**	call <fnname> [<name>: <expr>...]
	*/
	public static function _call ($parts, $data)
	{
		self::$data->internal_call = 1 + self::$data->internal_call;

		$n_args = new Map();

		for ($i = 2; $i < $parts->length(); $i += 2)
		{
			$key = Expr::value($parts->get($i), $data);
			if (substr($key, -1) == ':')
				$key = substr($key, 0, strlen($key)-1);
	
			$n_args->set($key, Expr::value($parts->get($i+1), $data));
		}

		try {
			$p_args = self::$data->args;
			self::$data->args = $n_args;

			self::process(Expr::expand($parts->get(1), $data), false);

			self::$data->args = $p_args;
		}
		catch (SubReturn $e) {
			$response = self::$response;
		}
		catch (FalseError $e) {
			echo 'ERROR: ' . $e;
			exit;
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

Expr::register('math::rand', function() { return Math::rand(); });

Expr::register('utils::sleep', function($args) { sleep($args->get(1)); return null; });
Expr::register('utils::base64:encode', function($args) { return base64_encode ($args->get(1)); });
Expr::register('utils::base64:decode', function($args) { return base64_decode ($args->get(1)); });

Expr::register('header', function(...$args) { return Wind::header(...$args); });
Expr::register('contentType', function(...$args) { return Wind::contentType(...$args); });
Expr::register('stop', function(...$args) { return Wind::stop(...$args); });
Expr::register('return', function(...$args) { return Wind::return(...$args); });
Expr::register('_echo', function(...$args) { return Wind::_echo(...$args); });
Expr::register('_trace', function(...$args) { return Wind::_trace(...$args); });
Expr::register('_call', function(...$args) { return Wind::_call(...$args); });
Expr::register('error', function(...$args) { return Wind::error(...$args); });

/* ****************************************************************************** */
Wind::init();
