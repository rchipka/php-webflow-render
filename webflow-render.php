<?php
function webflow_render($keys, $data = null, $callback = null) {
  if (is_callable($data)) {
    $callback = $data;
  } else if (is_array($data)) {
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

  do_action('before_webflow_render', $keys);

  do_action('webflow_render', $keys, $callback);

  do_action('after_webflow_render', $keys);
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

  add_action('webflow_render', function ($keys, $callback) use ($elements, $twig) {
    $max_similarity = 0;
    $matches = [];

    $render_context = context($keys);

    $render_context->parent = context();
    
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

    ob_start();

    $slots = (object) [];
    context()->set('slots', $slots);

    try {
      // extract($GLOBALS['wf_context']);
      echo eval('?>' . $twig->render($element->class, $GLOBALS['wf_context']));
    } catch (\Exception $e) {
      context()->log($e->getMessage());
    }

    $output = ob_get_clean();

    if (is_callable($callback)) {
      call_user_func($callback);
    }

    foreach (get_object_vars($slots) as $name => $slot) {
      do_action('webflow_slot', $slot, $name);
      do_action('webflow_slot_' . $name, $slot, $name);
      
      $regex = '/<!-- slot-' . $slot->hash . ' -->/';
      $chunks = array_chunk($slot->data, ceil(sizeof($slot->data) / $slot->total));

      foreach ($chunks as $array) {
        $output = preg_replace($regex, implode(' ', $array), $output, 1);
      }
    }

    if ($element->slot && context()->parent) {
      context()->parent->get('slots')->{$element->slot}->data[] = $output;
    } else {
      echo $output;
    }

    $render_context->exit();
    
    $element->rendering = false;

    $GLOBALS['wf_element_index'][$key]++;
  }, 5, 2);
}

function webflow_slot($name) {
  $slots = context()->get('slots');

  if (!$slots->{$name}) {
    $slots->{$name} = (object) [
      'hash' => md5(rand()),
      'total' => 0,
      'data' => [],
    ];
  }

  $slots->{$name}->total++;

  // We use a hash so that the slot is unique to the current context

  return '<!-- slot-' . $slots->{$name}->hash . ' -->';
}

