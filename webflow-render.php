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

  $twig->addFunction(new Twig_SimpleFunction('webflow_render', 'webflow_render'));
  
  $twig->addFunction(new Twig_SimpleFunction('dump', function ($prop) {
    echo print_r($prop, 1);
  }));

  $twig->addFunction(new Twig_SimpleFunction('get', function ($prop) {
    return context()->get($prop);
  }));

  $twig->addFunction(new Twig_SimpleFunction('title', function ($post_id = null) {
    return the_title($post_id);
  }));
  
  $twig->addFunction(new Twig_SimpleFunction('the_title', function ($post_id = null) {
    return the_title($post_id);
  }));
  
  $twig->addFunction(new Twig_SimpleFunction('content', function ($post_id = null) {
    return the_content($post_id);
  }));
  
  $twig->addFunction(new Twig_SimpleFunction('the_content', function ($post_id = null) {
    return the_content($post_id);
  }));

  $twig->addFunction(new Twig_SimpleFunction('field', function ($field, $post_id = null) {
    $ret = get_sub_field_object($field, $post_id)['value'] ?: get_field_object($field, $post_id)['value'];

    return $ret;
  }));

  $twig->addFunction(new Twig_SimpleFunction('get_field', function ($field, $post_id = null) {
    return get_sub_field($field, $post_id) ?: get_field($field, $post_id);
  }));
  $twig->addFunction(new Twig_SimpleFunction('the_field', function ($field, $post_id = null) {
    return the_sub_field($field, $post_id) ?: the_field($field, $post_id);
  }));

  $twig->addFunction(new Twig_SimpleFunction('the_row', function () {
    return the_row();
  }));

  $twig->addFunction(new Twig_SimpleFunction('have_rows', function ($field, $post_id = null) {
    return have_rows($field, $post_id);
  }));

  $twig->addFunction(new Twig_SimpleFunction('loop_context', function ($loop, $key, $seq) {
    $value = $seq[$loop['index0']];
    // error_log(json_encode($loop));
    // error_log(json_encode($key));
    // error_log(json_encode($seq));
    // error_log(print_r($value, 1));

    context()->set($key, $value);

    context()->set('loop_item', $value);

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

