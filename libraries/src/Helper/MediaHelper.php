<?php
/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2013 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Helper;

\defined('JPATH_PLATFORM') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Image\Image;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

/**
 * Media helper class
 *
 * @since  3.2
 */
class MediaHelper
{
	/**
	 * Default sizes for responsive images
	 *
	 * @var    array
	 * @since  4.1
	 */
	protected static $responsiveSizes = array('800x600', '600x400', '400x200');

	/**
	 * Checks if the file is an image
	 *
	 * @param   string  $fileName  The filename
	 *
	 * @return  boolean
	 *
	 * @since   3.2
	 */
	public static function isImage($fileName)
	{
		static $imageTypes = 'xcf|odg|gif|jpg|jpeg|png|bmp';

		return preg_match("/\.(?:$imageTypes)$/i", $fileName);
	}

	/**
	 * Gets the file extension for purposed of using an icon
	 *
	 * @param   string  $fileName  The filename
	 *
	 * @return  string  File extension to determine icon
	 *
	 * @since   3.2
	 */
	public static function getTypeIcon($fileName)
	{
		return strtolower(substr($fileName, strrpos($fileName, '.') + 1));
	}

	/**
	 * Get the Mime type
	 *
	 * @param   string   $file     The link to the file to be checked
	 * @param   boolean  $isImage  True if the passed file is an image else false
	 *
	 * @return  mixed    the mime type detected false on error
	 *
	 * @since   3.7.2
	 */
	public static function getMimeType($file, $isImage = false)
	{
		// If we can't detect anything mime is false
		$mime = false;

		try
		{
			if ($isImage && \function_exists('exif_imagetype'))
			{
				$mime = image_type_to_mime_type(exif_imagetype($file));
			}
			elseif ($isImage && \function_exists('getimagesize'))
			{
				$imagesize = getimagesize($file);
				$mime      = $imagesize['mime'] ?? false;
			}
			elseif (\function_exists('mime_content_type'))
			{
				// We have mime magic.
				$mime = mime_content_type($file);
			}
			elseif (\function_exists('finfo_open'))
			{
				// We have fileinfo
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$mime  = finfo_file($finfo, $file);
				finfo_close($finfo);
			}
		}
		catch (\Exception $e)
		{
			// If we have any kind of error here => false;
			return false;
		}

		// If we can't detect the mime try it again
		if ($mime === 'application/octet-stream' && $isImage === true)
		{
			$mime = static::getMimeType($file, false);
		}

		// We have a mime here
		return $mime;
	}

	/**
	 * Checks the Mime type
	 *
	 * @param   string  $mime       The mime to be checked
	 * @param   string  $component  The optional name for the component storing the parameters
	 *
	 * @return  boolean  true if mime type checking is disabled or it passes the checks else false
	 *
	 * @since   3.7
	 */
	private function checkMimeType($mime, $component = 'com_media'): bool
	{
		$params = ComponentHelper::getParams($component);

		if ($params->get('check_mime', 1))
		{
			$allowedMime = $params->get(
				'upload_mime',
				'image/jpeg,image/gif,image/png,image/bmp,application/msword,application/excel,' .
					'application/pdf,application/powerpoint,text/plain,application/x-zip'
			);

			// Get the mime type configuration
			$allowedMime = array_map('trim', explode(',', $allowedMime));

			// Mime should be available and in the allowed list
			return !empty($mime) && \in_array($mime, $allowedMime);
		}

		// We don't check mime at all or it passes the checks
		return true;
	}

	/**
	 * Checks if the file can be uploaded
	 *
	 * @param   array   $file                File information
	 * @param   string  $component           The option name for the component storing the parameters
	 * @param   string  $allowedExecutables  Array of executable file types that shall be whitelisted
	 *
	 * @return  boolean
	 *
	 * @since   3.2
	 */
	public function canUpload($file, $component = 'com_media', $allowedExecutables = array())
	{
		$app    = Factory::getApplication();
		$params = ComponentHelper::getParams($component);

		if (empty($file['name']))
		{
			$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_UPLOAD_INPUT'), 'error');

			return false;
		}

		if ($file['name'] !== File::makeSafe($file['name']))
		{
			$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_WARNFILENAME'), 'error');

			return false;
		}

		$filetypes = explode('.', $file['name']);

		if (\count($filetypes) < 2)
		{
			// There seems to be no extension
			$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_WARNFILETYPE'), 'error');

			return false;
		}

		array_shift($filetypes);

		// Media file names should never have executable extensions buried in them.
		$executable = array(
			'php', 'js', 'exe', 'phtml', 'java', 'perl', 'py', 'asp', 'dll', 'go', 'ade', 'adp', 'bat', 'chm', 'cmd', 'com', 'cpl', 'hta', 'ins', 'isp',
			'jse', 'lib', 'mde', 'msc', 'msp', 'mst', 'pif', 'scr', 'sct', 'shb', 'sys', 'vb', 'vbe', 'vbs', 'vxd', 'wsc', 'wsf', 'wsh', 'html', 'htm',
		);

		// Remove allowed executables from array
		if (count($allowedExecutables))
		{
			$executable = array_diff($executable, $allowedExecutables);
		}

		$check = array_intersect($filetypes, $executable);

		if (!empty($check))
		{
			$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_WARNFILETYPE'), 'error');

			return false;
		}

		$filetype = array_pop($filetypes);

		$allowable = $params->get(
			'upload_extensions',
			'bmp,csv,doc,gif,ico,jpg,jpeg,odg,odp,ods,odt,pdf,png,ppt,txt,xcf,xls,BMP,' .
				'CSV,DOC,GIF,ICO,JPG,JPEG,ODG,ODP,ODS,ODT,PDF,PNG,PPT,TXT,XCF,XLS'
		);
		$allowable = array_map('trim', explode(',', $allowable));
		$ignored   = array_map('trim', explode(',', $params->get('ignore_extensions')));

		if ($filetype == '' || $filetype == false || (!\in_array($filetype, $allowable) && !\in_array($filetype, $ignored)))
		{
			$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_WARNFILETYPE'), 'error');

			return false;
		}

		$maxSize = (int) ($params->get('upload_maxsize', 0) * 1024 * 1024);

		if ($maxSize > 0 && (int) $file['size'] > $maxSize)
		{
			$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_WARNFILETOOLARGE'), 'error');

			return false;
		}

		if ($params->get('restrict_uploads', 1))
		{
			$images = array_map('trim', explode(',', $params->get('image_extensions')));

			if (\in_array($filetype, $images))
			{
				// If tmp_name is empty, then the file was bigger than the PHP limit
				if (!empty($file['tmp_name']))
				{
					// Get the mime type this is an image file
					$mime = static::getMimeType($file['tmp_name'], true);

					// Did we get anything useful?
					if ($mime != false)
					{
						$result = $this->checkMimeType($mime, $component);

						// If the mime type is not allowed we don't upload it and show the mime code error to the user
						if ($result === false)
						{
							$app->enqueueMessage(Text::sprintf('JLIB_MEDIA_ERROR_WARNINVALID_MIMETYPE', $mime), 'error');

							return false;
						}
					}
					// We can't detect the mime type so it looks like an invalid image
					else
					{
						$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_WARNINVALID_IMG'), 'error');

						return false;
					}
				}
				else
				{
					$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_WARNFILETOOLARGE'), 'error');

					return false;
				}
			}
			elseif (!\in_array($filetype, $ignored))
			{
				// Get the mime type this is not an image file
				$mime = static::getMimeType($file['tmp_name'], false);

				// Did we get anything useful?
				if ($mime != false)
				{
					$result = $this->checkMimeType($mime, $component);

					// If the mime type is not allowed we don't upload it and show the mime code error to the user
					if ($result === false)
					{
						$app->enqueueMessage(Text::sprintf('JLIB_MEDIA_ERROR_WARNINVALID_MIMETYPE', $mime), 'error');

						return false;
					}
				}
				// We can't detect the mime type so it looks like an invalid file
				else
				{
					$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_WARNINVALID_MIME'), 'error');

					return false;
				}

				if (!Factory::getUser()->authorise('core.manage', $component))
				{
					$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_WARNNOTADMIN'), 'error');

					return false;
				}
			}
		}

		$xss_check = file_get_contents($file['tmp_name'], false, null, -1, 256);

		$html_tags = array(
			'abbr', 'acronym', 'address', 'applet', 'area', 'audioscope', 'base', 'basefont', 'bdo', 'bgsound', 'big', 'blackface', 'blink',
			'blockquote', 'body', 'bq', 'br', 'button', 'caption', 'center', 'cite', 'code', 'col', 'colgroup', 'comment', 'custom', 'dd', 'del',
			'dfn', 'dir', 'div', 'dl', 'dt', 'em', 'embed', 'fieldset', 'fn', 'font', 'form', 'frame', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'head', 'hr', 'html', 'iframe', 'ilayer', 'img', 'input', 'ins', 'isindex', 'keygen', 'kbd', 'label', 'layer', 'legend', 'li', 'limittext',
			'link', 'listing', 'map', 'marquee', 'menu', 'meta', 'multicol', 'nobr', 'noembed', 'noframes', 'noscript', 'nosmartquotes', 'object',
			'ol', 'optgroup', 'option', 'param', 'plaintext', 'pre', 'rt', 'ruby', 's', 'samp', 'script', 'select', 'server', 'shadow', 'sidebar',
			'small', 'spacer', 'span', 'strike', 'strong', 'style', 'sub', 'sup', 'table', 'tbody', 'td', 'textarea', 'tfoot', 'th', 'thead', 'title',
			'tr', 'tt', 'ul', 'var', 'wbr', 'xml', 'xmp', '!DOCTYPE', '!--',
		);

		foreach ($html_tags as $tag)
		{
			// A tag is '<tagname ', so we need to add < and a space or '<tagname>'
			if (stripos($xss_check, '<' . $tag . ' ') !== false || stripos($xss_check, '<' . $tag . '>') !== false)
			{
				$app->enqueueMessage(Text::_('JLIB_MEDIA_ERROR_WARNIEXSS'), 'error');

				return false;
			}
		}

		return true;
	}

	/**
	 * Calculate the size of a resized image
	 *
	 * @param   integer  $width   Image width
	 * @param   integer  $height  Image height
	 * @param   integer  $target  Target size
	 *
	 * @return  array  The new width and height
	 *
	 * @since   3.2
	 */
	public static function imageResize($width, $height, $target)
	{
		/*
		 * Takes the larger size of the width and height and applies the
		 * formula accordingly. This is so this script will work
		 * dynamically with any size image
		 */
		if ($width > $height)
		{
			$percentage = ($target / $width);
		}
		else
		{
			$percentage = ($target / $height);
		}

		// Gets the new value and applies the percentage, then rounds the value
		$width  = round($width * $percentage);
		$height = round($height * $percentage);

		return array($width, $height);
	}

	/**
	 * Counts the files and directories in a directory that are not php or html files.
	 *
	 * @param   string  $dir  Directory name
	 *
	 * @return  array  The number of media files and directories in the given directory
	 *
	 * @since   3.2
	 */
	public function countFiles($dir)
	{
		$total_file = 0;
		$total_dir  = 0;

		if (is_dir($dir))
		{
			$d = dir($dir);

			while (($entry = $d->read()) !== false)
			{
				if ($entry[0] !== '.' && strpos($entry, '.html') === false && strpos($entry, '.php') === false && is_file($dir . DIRECTORY_SEPARATOR . $entry))
				{
					$total_file++;
				}

				if ($entry[0] !== '.' && is_dir($dir . DIRECTORY_SEPARATOR . $entry))
				{
					$total_dir++;
				}
			}

			$d->close();
		}

		return array($total_file, $total_dir);
	}

	/**
	 * Small helper function that properly converts any
	 * configuration options to their byte representation.
	 *
	 * @param   string|integer  $val  The value to be converted to bytes.
	 *
	 * @return integer The calculated bytes value from the input.
	 *
	 * @since 3.3
	 */
	public function toBytes($val)
	{
		switch ($val[\strlen($val) - 1])
		{
			case 'M':
			case 'm':
				return (int) $val * 1048576;
			case 'K':
			case 'k':
				return (int) $val * 1024;
			case 'G':
			case 'g':
				return (int) $val * 1073741824;
			default:
				return $val;
		}
	}

	/**
	 * Method to check if the given directory is a directory configured in FileSystem - Local plugin
	 *
	 * @param   string  $directory
	 *
	 * @return  boolean
	 *
	 * @since   4.0.0
	 */
	public static function isValidLocalDirectory($directory)
	{
		$plugin = PluginHelper::getPlugin('filesystem', 'local');

		if ($plugin)
		{
			$params = new Registry($plugin->params);

			$directories = $params->get('directories', '[{"directory": "images"}]');

			// Do a check if default settings are not saved by user
			// If not initialize them manually
			if (is_string($directories))
			{
				$directories = json_decode($directories);
			}

			foreach ($directories as $directoryEntity)
			{
				if ($directoryEntity->directory === $directory)
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Method to generate different-sized versions of form images
	 *
	 * @param   array  $initImages   images before submission
	 * @param   array  $finalImages  images during submission
	 * @param   array  $sizes        array of strings. Example: $sizes = array('1200x800','800x600');
	 *
	 * @return  array  generated images
	 *
	 * @since   4.1.0
	 */
	public static function generateFormResponsiveImages($initImages, $finalImages, $sizes = null)
	{
		// Use default if sizes are not provided
		if (is_null($sizes))
		{
			$sizes = static::$responsiveSizes;
		}

		$imagesGenerated = [];

		foreach ($finalImages as $key => $finalImage)
		{
			// Get image names, currently they are: imgName#joomlaImage://imgPath
			$initImage = explode("#", $initImages[$key])[0];
			$finalImage = explode("#", $finalImage)[0];

			// Remove previously generated images if original is changed
			if ($initImage !== "" && $initImage !== $finalImage)
			{
				$imgObj = new Image(JPATH_ROOT . '/' . $initImage);
				$imgObj->deleteMultipleSizes();
			}

			// Generate new responsive images if file exists
			if (is_file(JPATH_ROOT . '/' . $finalImage))
			{
				$imgObj = new Image(JPATH_ROOT . '/' . $finalImage);
				$imgObj->createMultipleSizes($sizes);
				$imagesGenerated[] = $finalImage;
			}
		}

		return $imagesGenerated;
	}

	/**
	 * Method to generate different-sized versions of content images
	 *
	 * @param   string  $initContent   content before submission
	 * @param   string  $finalContent  content during submission
	 * @param   array   $sizes         array of strings. Example: $sizes = array('1200x800','800x600');
	 *
	 * @return  array   generated images
	 *
	 * @since   4.1.0
	 */
	public static function generateContentResponsiveImages($initContent, $finalContent, $sizes = null)
	{
		// Use default if sizes are not provided
		if (is_null($sizes))
		{
			$sizes = static::$responsiveSizes;
		}

		// Get src of img tag: <img src="images/joomla.png" /> - images/joomla.png
		$pattern = '/<*img[^>]*src *= *["\']?([^"\']*)/';

		// Get initial and final images from content and remove duplicates
		$initImages = preg_match_all($pattern, $initContent, $matched) ? array_unique($matched[1]) : [];
		$finalImages = preg_match_all($pattern, $finalContent, $matched) ? array_unique($matched[1]) : [];

		foreach ($initImages as $initImage)
		{
			// Remove previously generated images if original is changed
			if (!in_array($initImage, $finalImages))
			{
				$imgObj = new Image(JPATH_ROOT . '/' . $initImage);
				$imgObj->deleteMultipleSizes();
			}
		}

		$imagesGenerated = [];

		foreach ($finalImages as $finalImage)
		{
			// Generate new responsive images if file exists
			if (is_file(JPATH_ROOT . '/' . $finalImage))
			{
				$imgObj = new Image(JPATH_ROOT . '/' . $finalImage);
				$imgObj->createMultipleSizes($sizes);
				$imagesGenerated[] = $finalImage;
			}
		}

		return $imagesGenerated;
	}

	/**
	 * Method to add srcset and sizes attributes to img tags of content
	 *
	 * @param   string  $content  content to which srcset attributes must be inserted
	 * @param   array   $sizes    array of strings. Example: $sizes = array('1200x800','800x600');
	 *
	 * @return  string  content with srcset attributes inserted
	 *
	 * @since   4.1.0
	 */
	public static function addContentSrcsetAndSizes($content, $sizes = null)
	{
		// Use default if sizes are not provided
		if (is_null($sizes))
		{
			$sizes = static::$responsiveSizes;
		}

		// Get src of img tags: <img src="images/joomla.png" /> - images/joomla.png and remove duplicates
		$images = preg_match_all('/<*img[^>]*src *= *["\']?([^"\']*)/', $content, $matched) ? array_unique($matched[1]) : [];

		// Generate srcset and sizes for all images
		$resultContent = $content;

		foreach ($images as $image)
		{
			$imgObj = new Image(JPATH_ROOT . '/' . $image);

			if ($responsiveImages = $imgObj->generateMultipleSizes($sizes))
			{
				// Get image info
				$imgPath       = $imgObj->getPath();
				$pathInfo      = pathinfo($imgPath);
				$filename      = $pathInfo['filename'];
				$fileExtension = $pathInfo['extension'] ?? '';

				// Get path to the responsive images folder
				$imageArr = explode('/', $image);
				$destFolder = implode('/', array_replace($imageArr, [count($imageArr) - 1 => 'responsive']));

				// Generate sizes and srcset attributes
				$sizesAttr = sprintf('(max-width: %1$dpx) 100vw, %1$dpx', $imgObj->getWidth());
				$srcsetAttr = "";

				foreach ($responsiveImages as $key => $responsiveImage)
				{
					// Get image properties
					$imageWidth  = $responsiveImage->getWidth();
					$imageHeight = $responsiveImage->getHeight();

					// Generate image name
					$imageFileName = $filename . '_' . $imageWidth . 'x' . $imageHeight . '.' . $fileExtension;

					// Insert srcset value for current responsive image
					$srcsetAttr .= sprintf(
						'%s %dw%s ', $destFolder . '/' . $imageFileName, $imageWidth, $key !== count($responsiveImages) - 1 ? ',' : ''
					);
				}


				$resultContent = preg_replace(
					'/(<img [^>]+' . preg_quote($image, '/') . '.*?) \/>/',
					'$1 srcset="' . $srcsetAttr . '" sizes="' . $sizesAttr . '" />',
					$resultContent
				);
			}
		}

		return $resultContent;
	}
}
