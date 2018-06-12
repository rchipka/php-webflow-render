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

  context($keys)->enter()->exit();
}

$GLOBALS['wf_context'] = [];

function webflow_init($jsonFile) {
  $elements = @json_decode(file_get_contents($jsonFile));

  if (!$elements) {
    return;
  }

  $templates = [];

  foreach ($elements as $i => $e) {
    $e->class = $e->class . ' - ' . $i;

    $templates[$e->class] = $e->html;
  }

  $loader = new Twig_Loader_Array($templates);

  $twig = new Twig_Environment($loader);

  $twig->addFunction(new Twig_Function('webflow_render', 'webflow_render'));
  
  $twig->addFunction(new Twig_Function('get', function ($prop) {
    return context()->get($prop);
  }));

  $twig->addFunction(new Twig_Function('loop_context', function ($loop, $key, $seq) {
    $value = $seq[$loop['index0']];
    // error_log(json_encode($loop));
    // error_log(json_encode($key));
    // error_log(json_encode($seq));
    // error_log(print_r($value, 1));

    context()->set($key, $value);


    if ($value instanceof WP_Post) {
      setup_postdata($value);

      if ($key !== 'post') {
        context()->set('post', $value);
      }

      if ($key !== 'item') {
        context()->set('item', $value);
      }

      return true;
    }
  }));

  $GLOBALS['wf_element_index'] = [];

  add_action('enter_context', function () use ($elements, $twig) {
    $max_similarity = 0;
    $matches = [];

    // error_log(json_encode(context()->keys));

    foreach ($elements as $i => $e) {
      $similarity = context()->match($e->keys);

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

    if (!$match || sizeof($match) < 1) {
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

    $data = context()->data();

    try {
      echo $twig->render($element->class, $GLOBALS['wf_context']);
    } catch (\Exception $e) {
      context()->log($e->getMessage());
    }
    
    $element->rendering = false;

    $GLOBALS['wf_element_index'][$key]++;
  });
}

