<?php

require("phpFunctions.php");

class Obfuscator
{

  const STRING_PATTERN = "/(\".*?[^\\]\")|('.*?[^\\]')/";
  const VARIABLE_PATTERN = "/\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/";
  const FUNCTION_DECLARATION_PATTERN = "/function[\s\n]+(\S+)[\s\n]*\(/";
  const FUNCTION_PATTERN = "/function[\s\n]+(\S+)[\s\n]*\(/"; //this regex does not work
  const CLASS_DECLARATION_PATTERN = "/(\b(final|abstract)\s+)*\bclass\s+person\b(\s+(extends\s+\w+|implements\s+\w+(\s*,\s*\w+)*))*\s*{/";

  private $removeWhitespace = true;
  private $obfuscateVariables = true;
  private $obfuscateFunctionNames = false;//Does not work, should check the regex that matches functions
  private $classesToReplace = array();
  private $functionsToReplace = array();
  private $excludedFunctions = array();

  /**
   * Starts the obfuscation process. All parameters related to the obfuscation process must be set manually,
   * and not through the use of a ObfuscationConfig file.
   *
   * param file PHP file to obfuscate
   **/
  public function start($file)
  {
    $this->obfuscate($file);

    if ($this->obfuscateFunctionNames)
    {
      // if the option is on, then the list of function names was constructed in the last pass.
      $this->renameFunctions($file);
    }
  }

  /**
   * Determines if a character index of a string occurs within one of the regex matches from the
   * same string
   *
   * param matchCollection Matches generated using Regex
   * param stringIdx Index of the character to check for inclusion in one of the matches
   *
   * returns Flag indicating whether the index was in one of the ranges.
   **/
  private function inMatchedCollection($matchCollection, $stringIdx)
  {
    foreach ($matchCollection as $match)
    {
      if($match[1] < $stringIdx && ($match[1] + strlen($match[0]) > $stringIdx))
          return true;
    }

    return false;
  }

  /**
   * Determine the index of a string within a larger string. Strings embdedded in the haystack are not
   * checked for the target needle... that is to say quoted strings appearing in the haystack as they
   * would in source code are ignored. if searching for the string TEST, it will be found only if it does
   * not appear within quotes.
   *
   * param needle String for which we are searching
   * haystack String being searched
   * start starting position at which to start searching the haystack
   * param exclusionaryStrings whether or not to consider the starting position when looking for strings to avoid
   *
   **/
  private function indexOf($needle, $haystack, $start, $exclusionaryStrings)
  {
    $avoid = array();
    // find the strings so we can ignore their contents.
    if($exclusionaryStrings)
        preg_match(self::STRING_PATTERN, $haystack, $avoid, PREG_OFFSET_CAPTURE, $start);
    else
        preg_match(self::STRING_PATTERN, $haystack, $avoid, PREG_OFFSET_CAPTURE);

    $found = strpos($haystack, $needle, $start);

    // if it didnt find anything, return
    if ($found < 0)
        return $found;

    // if it found something and it happens to be in a range we should avoid, recurse from that position
    if (inMatchedCollection($avoid, $found))
        return indexOf($needle, $haystack, $found + 1, $exclusionaryStrings);

    return $found;
  }

  /**
   * Obfuscates the variable names in a block of code and collects function names for use in the second pass of obfuscation
   *
   * codeBlock Block of PHP code to obfuscate.
   *
   * returns Obfuscated block of code.
   **/
  private function obfuscateBlock($codeBlock)
  {
      // first remove comments in the form /* */
      $start = 0;
      while (!($start === false))
      {
        $start = strpos($codeBlock, "/*");
        if (!($start === false))
        {
          $end = strpos($codeBlock, "*/", $start);
          if (!($end === false))
            $codeBlock = substr($codeBlock, $start, $end-$start+2);
        }
      }
      // remove other forms of comments
      if ($this->removeWhitespace)
      {
        $codeBlock = $this->removeComments("//", $codeBlock);
        $codeBlock = $this->removeComments("#", $codeBlock);
      }

      // rename variables
      if ($this->obfuscateVariables)
      {
        $codeBlock = $this->renameVariables($codeBlock);
      }

      // remove newlines
      if ($this->removeWhitespace)
      {
        $codeBlock = preg_replace("/([\t\n\r])/", " ", $codeBlock);
      }

      if ($this->obfuscateFunctionNames)
      {
          $collection = array();
          preg_match(self::CLASS_DECLARATION_PATTERN, $codeBlock, $collection);

          foreach ($collection as $match)
              array_push($classesToReplace, $match);

          $collection = array();
          preg_match(self::FUNCTION_DECLARATION_PATTERN, $codeBlock, $collection);

          foreach ($collection as $match)
          {
            // dont add class constructors to the function list
            if(!in_array($match, $this->classesToReplace) && !in_array($match, $this->excludedFunctions) && !PHPFunctions::contains($match))
              array_push($this->functionsToReplace, $match);
          }
      }

      return $codeBlock;
  }

  /**
   * Returns an MD5 representation of a string
   * param originalString String to encode
   **/
  private function getMD5($originalString)
  {
    return md5($originalString);
  }

  /**
   * Renames all the variables in a block of PHP code to their MD5 modified equivalents.
   *
   * param codeBlock Block of PHP code to use
   *
   * return obfuscated code block
   **/
  private function renameVariables($codeBlock)
  {
      $collection = array();
      preg_match(self::VARIABLE_PATTERN, $codeBlock, $collection, PREG_OFFSET_CAPTURE);

      // replace the matches backwards so we dont need to keep track of modification offsets.
      for ($i = count($collection) - 1; $i >= 0; $i--)
      {
          $match = $collection[$i];
          if (!in_array($match[0], $this->excludedVariables))
          {
              $encodedVar = "$R" + getMD5($match[0]);
              $codeBlock = substr_replace($codeBlock, $encodedVar, $match[1], strlen($match[0]));
          }
      }

      return $codeBlock;
  }

  /**
   * Removes a type of single line comments from a block of code
   *
   * param form Form of the Comment. Can be // or #, or anything else determined to signify a comment
   *
   * param code Block of code from which to remove comments
   *
   * Obfuscated block of code
   **/
  private function removeComments($form, $code)
  {
    $start = 0;
    while ($start >= 0)
    {
      $start = strpos($code, $form, $start);
      if (!($start === false))
      {
        $len = 0;
        $end = strpos($code, "?>", $start);
        if (!($end === false))
          $len = 2;

        if ($end === false)
        {
          $end = strpos($code, "\n", $start);
          $len = 1;
        }

        if ($end === false)
          $end = strlen($code) - 1;

        if (!($end === false))
        {
          $code = substr_replace($code, "",  $start, $end - $start - ($len - 1));
        }
      }
      else
        $start = -1;
    }

    return $code;
  }

  /**
   * Renames function declarations and function calls in the specified file
   * based on the function names gathered in a previous obfuscation pass.
   *
   * param filename Filename to obfuscate
   **/
  private function renameFunctions($filename)
  {
    // if the file does not end in ".php", return
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if (strtolower($ext) != "php")
      return;

    $fileContents = file_get_contents($filename);

    $start = 0;
    while ($start >= 0)
    {
      $blockSize;
      $codeBlock = $this->getCodeBlock($fileContents, $start, $start, $blockSize);

      if ($start >= 0)
      {
        $collection = array();
        preg_match(self::FUNCTION_PATTERN, $codeBlock, $collection, PREG_OFFSET_CAPTURE);

        for ($i = sizeof($collection)- 1; $i >= 0; $i--)
        {
          $match = $collection[$i];
          $replacementFunctionIdx = array_search($match[0], $this->functionsToReplace);

          if ($replacementFunctionIdx >= 0)
          {
            $codeBlock = substr_replace($codeBlock, "", $match[1], strlen($match[0]));
            $codeBlock = substr_replace($codeBlock, "F" . $this->getMD5($match[0]), $match[1]);
          }
          else
          {
            // if it isnt function use, it may be class construction
            $replacementFunctionIdx = array_search($match[0], $this->classesToReplace);
            if ($replacementFunctionIdx >= 0)
            {
              $codeBlock = substr_replace($codeBlock, "", $match[1], strlen($match[2]));
              $codeBlock = substr_replace($codeBlock, "C" . $this->getMD5($match[0]), $match[1]);
            }
          }
        }

        // match class declarations
        preg_match(self::CLASS_DECLARATION_PATTERN, $codeBlock, $collection, PREG_OFFSET_CAPTURE);
        for ($i = sizeof($collection) - 1; $i >= 0; $i--)
        {
          $match = $collection[i];
          $replacementClassIdx = array_search($match[0], $this->classesToReplace);

          if (replacementClassIdx >= 0)
          {
            $codeBlock = substr_replace($codeBlock, "", $match[1], strlen($match[2]));
            $codeBlock = substr_replace($codeBlock, "C" . getMD5($match[0], $match[1]));
          }
        }

        $fileContents = substr_replace($fileContents, "", $start, $blockSize);
        $fileContents = substr_replace($fileContents, $codeBlock, $start);

        $start = $start + strlen($codeBlock);
      }
    }

    file_put_contents($filename . "obfuscated.php", $fileContents);
  }

  /**
   * Gets the next block of code
   *
   * param fileContents Complete contents of a PHP file
   * param start Starting position from which to search for a block of code
   * param blockStart Starting position of the next found block of code
   * param name blockSize Size of the block of code that was located
   **/
  private function getCodeBlock($fileContents, $start, &$blockStart, &$blockSize)
  {
    //find each php block
    $first = strpos($fileContents, "<?", $start);
    $first2 = strpos($fileContents, "<?php", $start);

    $len = 2;

    if (!($first === false) && $first2 < $first)
    {
      $first = $first2;
      $len = 5;
    }

    $start = $first + $len;

    if (!($first === false) && $first >= 0)
    {
      $end = strpos($fileContents, "?>", $first);
      if (!($end === false))
      {
        $blockStart = $start;
        $blockSize = $end - $start;

        $codeBlock = substr($fileContents, $blockStart, $blockSize);
        return $codeBlock;
      }
    }

    $blockStart = -1;
    $blockSize = -1;

    return $fileContents;
  }

  /**
   * Start obfuscation on a PHP file.
   *
   * param filename PHP file to obfuscate
   **/
  private function obfuscate($filename)
  {
    // if the file does not end in "php", return
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if (strtolower($ext) != "php")
      return;

    $fileContents = file_get_contents($filename);
    $blockStart = 0;
    while ($blockStart >= 0)
    {
      $blockSize;
      $codeBlock = $this->getCodeBlock($fileContents, $blockStart, $blockStart, $blockSize);
      if ($blockStart >= 0 && $blockSize > 0)
      {
        $codeBlock = $this->obfuscateBlock($codeBlock);
        $fileContents = substr_replace($fileContents, "", $blockStart, $blockSize);
        $fileContents = substr_replace($fileContents, $codeBlock, $blockStart, 0);

        $blockStart = $blockStart + strlen($codeBlock);
      }
    }
    file_put_contents($filename . "obfuscated.php", $fileContents);
  }
}

?>
