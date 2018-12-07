<?php
function webflow_render($keys, $data = null) {
  if ($data) {
    $item = $data['item'] ?: $data['post'];

    if ($item) {
      if (is_object($item)) {
        foreach (get_object_vars($item) as $key) {
          if (!$data[$key]) {
            $data[$key] = $item->{$key};
          }
        }
      } else if (is_array($item)) {
        foreach ($item as $key => $value) {
          if (!$data[$key]) {
            $data[$key] = $value;
          }
        }
      }
    }

    $GLOBALS['wf_context'] = $data;
  }

  do_action('webflow_render', $keys);
}

function webflow_init($jsonFile) {
  $elements = @json_decode(file_get_contents($jsonFile));

  $GLOBALS['wf_context'] = [];

  foreach ($GLOBALS as $key => $value) {
    $GLOBALS['wf_context'][$key] = $value;
  }

  if (!$elements) {
    return;
  }

  $templates = [];

  foreach ($elements as $i => $e) {
    $e->class = $e->class . ' - ' . $i;

    $templates[$e->class] = $e->html;
  }

  $loader = new Twig_Loader_Array($templates);

  $twig = new Twig_Environment($loader, [
    'autoescape' => false
  ]);
  
  $callback = function (array $args = array()) {
    return call_user_func_array($this->function, $args);
  };

  $functions = get_defined_functions();

  foreach (['user', 'internal'] as $type) {
    foreach ($functions[$type] as $function) {
      // 
      $ctx = new stdClass();
      $ctx->function = $function;

      $twig->addFunction(
        new Twig_SimpleFunction($function, Closure::bind($callback, $ctx), array('is_variadic' => true))
      );
    }
  }

  $GLOBALS['wf_element_index'] = [];

  add_action('webflow_render', function ($keys) use ($elements, $twig) {
    $max_similarity = 0;
    $matches = [];

    $render_context = context($keys);

    // error_log(json_encode(context()->keys));

    foreach ($elements as $i => $e) {
      $similarity = $render_context->match($e->keys);

      if (!$e->index) {
        $e->index = $i;
      }

      // error_log($e->class . ' => ' . $similarity);

      if (!$matches['' . $similarity]) {
        $matches['' . $similarity] = [];
      }

      $matches['' . $similarity][] = $e;

      if ($e->rendering) {
        continue;
      }

      if ($similarity > $max_similarity) {
        $max_similarity = $similarity;
      }
    }

    if ($max_similarity <= 0) {
      return;
    }

    $match = $matches['' . $max_similarity];

    if (!$match || sizeof($match) <= 0) {
      return;
    }

    usort($match, function ($a, $b) {
      return $a->index - $b->index;
    });

    // error_log(json_encode($match));

    $key = md5(implode('-', array_map(function ($e) {
      return $e->class;
    }, $match)));

    if (!$GLOBALS['wf_element_index'][$key]) {
      $GLOBALS['wf_element_index'][$key] = 0;
    }

    $index = $GLOBALS['wf_element_index'][$key];

    $element = $match[$index % sizeof($match)];

    if ($element->rendering) {
      return;
    }

    // context()->log($element->class . ' - ' . $element->index . ' - ' . $key . ' (' . $max_similarity . ') => ' . $index . ' / ' . sizeof($match));

    // error_log($element->html);

    $element->rendering = true;

    $render_context->enter();

    try {
      // extract($GLOBALS['wf_context']);
      echo eval('?>' . $twig->render($element->class, $GLOBALS['wf_context']));
    } catch (\Exception $e) {
      context()->log($e->getMessage());
    }

    $render_context->exit();
    
    $element->rendering = false;

    $GLOBALS['wf_element_index'][$key]++;
  }, 5, 1);
}

