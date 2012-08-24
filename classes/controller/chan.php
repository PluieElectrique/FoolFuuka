<?php

namespace Foolfuuka;

class Controller_Chan extends \Controller_Common
{

	protected $_theme = null;
	protected $_radix = null;
	protected $_to_bind = null;


	public function before()
	{
		parent::before();

		$this->_theme = \Theme::instance('foolfuuka');

		$pass = \Cookie::get('reply_password');
		$name = \Cookie::get('reply_name');
		$email = \Cookie::get('reply_email');

		// get the password needed for the reply field
		if(!$pass || $pass < 3)
		{
			$pass = \Str::random('alnum', 7);
			\Cookie::set('reply_password', $pass, 60*60*24*30);
		}

		$this->_to_bind = array(
			'user_name' => $name,
			'user_email' => $email,
			'user_pass' => $pass,
			'disable_headers' => false,
			'is_page' => false,
			'is_thread' => false,
			'is_last50' => false,
			'order' => false,
			'modifiers' => array(),
			'backend_vars' => array(
				'site_url'  => \Uri::base(),
				'default_url'  => \Uri::base(),
				'archive_url'  => \Uri::base(),
				'system_url'  => \Uri::base(),
				'api_url'   => \Uri::base(),
				'cookie_domain' => \Config::get('foolframe.cookie_prefix'),
				'cookie_prefix' => \Config::get('config.cookie.domain'),
				'selected_theme' => isset($this->_theme)?$this->_theme->get_selected_theme():'',
		//		'csrf_hash' => $this->security->get_csrf_hash(),
				'images' => array(
					'banned_image' => \Uri::base() . 'content/themes/default/images/banned-image.png',
					'banned_image_width' => 150,
					'banned_image_height' => 150,
					'missing_image' => \Uri::base() . 'content/themes/default/images/missing-image.jpg',
					'missing_image_width' => 150,
					'missing_image_height' => 150,
				),
				'gettext' => array(
					'submit_state' => __('Submitting'),
					'thread_is_real_time' => __('This thread is being displayed in real time.'),
					'update_now' => __('Update now')
				)
			)
		);

		$this->_theme->bind($this->_to_bind);
		$this->_theme->set_partial('tools_modal', 'tools_modal');
		$this->_theme->set_partial('tools_search', 'tools_search');
	}


	public function router($method, $params)
	{
		$segments = \Uri::segments();
		
		// the underscore function is never a board
		if (isset($segments[0]) && $segments[0] !== '_')
		{
			
			$this->_radix = \Radix::set_selected_by_shortname($method);

			if ($this->_radix)
			{
				$this->_theme->bind('radix', $this->_radix);
				$this->_to_bind['backend_vars']['board_shortname'] = $this->_radix->shortname;
				$this->_theme->bind($this->_to_bind);
				$this->_theme->set_title($this->_radix->formatted_title);
				$method = array_shift($params);
				
				// methods callable with a radix are prefixed with radix_
				if (method_exists($this, 'radix_'.$method))
				{
					return call_user_func_array(array($this, 'radix_'.$method), $params);
				}
				
				// a board and no function means we're out of the street
				throw new \HttpNotFoundException;
			}
		}
		
		$this->_radix = null;
		$this->_theme->bind('radix', null);
		
		if (method_exists($this, 'action_'.$method))
		{
			return call_user_func_array(array($this, 'action_'.$method), $params);
		}

		throw new \HttpNotFoundException;
	}


	/**
	 * The basic welcome message
	 *
	 * @access  public
	 * @return  Response
	 */
	public function action_index()
	{
		$this->_theme->bind('disable_headers', TRUE);
		return \Response::forge($this->_theme->build('index'));
	}
	

	/**
	 * The 404 action for the application.
	 *
	 * @access  public
	 * @return  Response
	 */
	public function action_404()
	{
		return \Response::forge($this->_theme->build('error',
					array(
					'error' => __('Page not found. You can use the search if you were looking for something!')
				)));
	}


	protected function error($error = null)
	{
		if (is_null($error))
		{
			return \Response::forge($this->_theme->build('error', array('error' => __('We encountered an unexpected error.'))));
		}
		return \Response::forge($this->_theme->build('error', array('error' => $error)));
	}

	
	public function action_theme($theme = 'default', $style = '')
	{
		$this->_theme->set_title(__('Changing Theme Settings'));

		if (!in_array($theme, $this->_theme->get_available_themes()))
		{
			$theme = 'default';
		}

		\Cookie::set('theme', $theme, 31536000, '/');
		
		if ($style !== '' && in_array($style, $this->_theme->get_available_styles($theme)))
		{
			\Cookie::set('theme_' . $theme . '_style', $style, 31536000, '/');
		}

		if (\Input::referrer())
		{
			$this->_theme->bind('url', \Input::referrer());
		}
		else
		{
			$this->_theme->bind('url', \Uri::base());
		}
		
		$this->_theme->set_layout('redirect');
		return \Response::forge($this->_theme->build('redirection'));
	}
	

	public function radix_page_mode($_mode = 'by_post')
	{
		$mode = $_mode === 'by_thread' ? 'by_thread' : 'by_post';
		$type = $this->_radix->archive ? 'archive' : 'board';
		\Cookie::set('default_theme_page_mode_'.$type, $mode);

		\Response::redirect($this->_radix->shortname);
	}


	public function radix_page($page = 1)
	{
		$order = \Cookie::get('default_theme_page_mode_'. ($this->_radix->archive ? 'archive' : 'board')) === 'by_thread'
			? 'by_thread' : 'by_post';

		$options = array(
			'per_page' => $this->_radix->threads_per_page,
			'per_thread' => 6,
			'order' => $order
		);

		return $this->latest($page, $options);
	}


	public function radix_ghost($page = 1)
	{
		$options = array(
			'per_page' => $this->_radix->threads_per_page,
			'per_thread' => 6,
			'order' => 'ghost'
		);

		return $this->latest($page, $options);
	}


	protected function latest($page = 1, $options = array())
	{
		\Profiler::mark('Controller Chan::latest Start');
		try
		{
			$board = \Board::forge()->get_latest()->set_radix($this->_radix)->set_page($page)->set_options($options);

			// execute in case there's more exceptions to handle
			$board->get_comments();
			$board->get_count();
		}
		catch (\Model\BoardException $e)
		{
			\Profiler::mark('Controller Chan::latest End Prematurely');
			return $this->error($e->getMessage());
		}

		if ($page > 1)
		{
			switch($options['order'])
			{
				case 'by_post':
					$order_string = __('Threads by latest replies');
					break;
				case 'by_thread':
					$order_string = __('Threads by creation');
					break;
				case 'ghost':
					$order_string = __('Threads by latest ghost replies');
					break;
			}

			$this->_theme->set_title(__('Page').' '.$page);
			$this->_theme->bind('section_title', $order_string.' - '.__('Page').' '.$page);
		}

		$this->_theme->bind(array(
			'is_page' => true,
			'board' => $board,
			'posts_per_thread' => $options['per_thread'] - 1,
			'order' => $options['order'],
			'pagination' => array(
				'base_url' => \Uri::create(array($this->_radix->shortname, $options['order'])),
				'current_page' => $page,
				'total' => $board->get_count()
			)
		));
		
		if (!$this->_radix->archive)
		{
			$this->_theme->set_partial('tools_new_thread_box', 'tools_reply_box');
		}

		\Profiler::mark_memory($this, 'Controller Chan $this');
		\Profiler::mark('Controller Chan::latest End');
		return \Response::forge($this->_theme->build('board'));
	}



	public function radix_thread($num = 0)
	{
		return $this->thread($num);
	}

	public function radix_last50($num = 0)
	{
		\Response::redirect($this->_radix->shortname.'/last/50/'.$num);
	}

	public function radix_last($limit = 0, $num = 0)
	{
		if (!\Board::is_natural($limit) || $limit < 1)
		{
			return $this->action_404();
		}

		return $this->thread($num, array('type' => 'last_x', 'last_limit' => $limit));
	}


	protected function thread($num = 0, $options = array())
	{
		\Profiler::mark('Controller Chan::thread Start');
		$num = str_replace('S', '', $num);

		try
		{
			$board = \Board::forge()->get_thread($num)->set_radix($this->_radix)->set_options($options);

			// execute in case there's more exceptions to handle
			$thread = $board->get_comments();
		}
		catch(\Model\BoardThreadNotFoundException $e)
		{
			\Profiler::mark('Controller Chan::thread End Prematurely');
			return $this->action_post($num);
		}
		catch (\Model\BoardException $e)
		{
			\Profiler::mark('Controller Chan::thread End Prematurely');
			return $this->error($e->getMessage());
		}

		// get the latest doc_id and latest timestamp for realtime stuff
		$latest_doc_id = $board->get_highest('doc_id')->doc_id;
		$latest_timestamp = $board->get_highest('timestamp')->timestamp;

		// check if we can determine if posting is disabled
		try
		{
			$thread_status = $board->check_thread_status();
		}
		catch (\Model\BoardThreadNotFoundException $e)
		{
			\Profiler::mark('Controller Chan::thread End Prematurely');
			return $this->error();
		}

		$this->_theme->set_title(\Radix::get_selected()->formatted_title.' &raquo; '.__('Thread').' #'.$num);
		$this->_theme->bind(array(
			'thread_id' => $num,
			'board' => $board,
			'is_thread' => true,
			'disable_image_upload' => $thread_status['disable_image_upload'],
			'thread_dead' => $thread_status['dead'],
			'latest_doc_id' => $latest_doc_id,
			'latest_timestamp' => $latest_timestamp,
			'thread_op_data' => $thread[$num]['op']
		));
		
		$backend_vars = $this->_theme->get_var('backend_vars');
		$backend_vars['thread_id'] = $num;
		$backend_vars['latest_timestamp'] = $latest_timestamp;
		$backend_vars['latest_doc_id'] = $latest_doc_id;
		$backend_vars['board_shortname'] = $this->_radix->shortname;
		$this->_theme->bind('backend_vars', $backend_vars);

		if (!$thread_status['dead'] || ($thread_status['dead'] && !$this->_radix->disable_ghost))
		{
			$this->_theme->set_partial('tools_reply_box', 'tools_reply_box');
		}

		\Profiler::mark_memory($this, 'Controller Chan $this');
		\Profiler::mark('Controller Chan::thread End');
		return \Response::forge($this->_theme->build('board'));
	}


	public function radix_gallery($page = 1)
	{
		try
		{
			$board = \Board::forge()->get_threads()->set_radix($this->_radix)->set_page($page)
				->set_options('per_page', 100);

			$comments = $board->get_comments();
		}
		catch (\Model\BoardException $e)
		{
			return $this->error($e->getMessage());
		}

		$this->_theme->bind('board', $board);
		return \Response::forge($this->_theme->build('gallery'));

	}


	public function radix_post($num)
	{
		try
		{
			$board = \Board::forge()->get_post()->set_radix($this->_radix)->set_options('num', $num);

			$comments = $board->get_comments();
		}
		catch (\Model\BoardMalformedInputException $e)
		{
			return $this->error(__('The post number you submitted is invalid.'));
		}
		catch (\Model\BoardPostNotFoundException $e)
		{
			return $this->error(__('The post you are looking for does not exist.'));
		}

		// it always returns an array
		$comment = $comments[0];

		$redirect =  \Uri::create($this->_radix->shortname.'/thread/'.$comment->thread_num.'/');

		if (!$comment->op)
		{
			$redirect .= '#'.$comment->num.($comment->subnum ? '_'.$comment->subnum :'');
		}

		$this->_theme->set_title(__('Redirecting'));
		$this->_theme->set_layout('redirect');
		return \Response::forge($this->_theme->build('redirect', array('url' => $redirect)));
	}


	/**
	 * Display all of the posts that contain the MEDIA HASH provided.
	 * As of 2012-05-17, fetching of posts with same media hash is done via search system.
	 * Due to backwards compatibility, this function will still be used for non-urlsafe and urlsafe hashes.
	 */
	public function radix_image()
	{
		// support non-urlsafe hash
		$uri = \Uri::segments();
		array_shift($uri);
		array_shift($uri);

		$imploded_uri = rawurldecode(implode('/', $uri));
		if (mb_strlen($imploded_uri) < 22)
		{
			return $this->error(__('Your image hash is malformed.'));
		}

		// obtain actual media hash (non-urlsafe)
		$hash = mb_substr($imploded_uri, 0, 22);
		if (strpos($hash, '/') !== false || strpos($hash, '+') !== false)
		{
			$hash = \Comment::urlsafe_b64encode(Comment::urlsafe_b64decode($hash));
		}

		// Obtain the PAGE from URI.
		$page = 1;
		if (mb_strlen($imploded_uri) > 28)
		{
			$page = substr($imploded_uri, 28);
		}

		// Fetch the POSTS with same media hash and generate the IMAGEPOSTS.
		$page = intval($page);
		Response::redirect(Uri::create(array(
			\Radix::get_selected()->shortname, 'search', 'image', $hash, 'order', 'desc', 'page', $page)), 'location', 301);
	}


	/**
	 * @param $filename
	 */
	public function radix_full_image($filename)
	{
		// Check if $filename is valid.
		if (!in_array(substr($filename, -3), array('gif', 'jpg', 'png')) || !\Board::is_natural(substr($filename, 0, 13)))
		{
			return $this->action_404();
		}

		// Fetch the FULL IMAGE with the FILENAME specified.
		$image = \Comment::get_full_media(get_selected_radix(), $filename);

		if (isset($image['media_link']))
		{
			redirect($image['media_link'], 'location', 303);
		}

		if (isset($image['error_type']))
		{
			// NOT FOUND, INVALID MEDIA HASH
			if ($image['error_type'] == 'no_record')
			{
				$this->output->set_status_header('404');
				$this->theme->set_title(__('Error'));
				$this->_set_parameters(
					array(
						'error' => __('There is no record of the specified image in our database.')
					)
				);
				$this->theme->build('error');
				return FALSE;
			}

			// NOT AVAILABLE ON SERVER
			if ($image['error_type'] == 'not_on_server')
			{
				$this->output->set_status_header('404');
				$this->theme->set_title(\Radix::get_selected()->formatted_title . ' &raquo; ' . __('Image Pruned'));
				$this->_set_parameters(
					array(
						'section_title' => __('Error 404: The image has been pruned from the server.'),
						'modifiers' => array('post_show_single_post' => TRUE, 'post_show_view_button' => TRUE),
						'posts' => array('posts' => array('posts' => array($image['result'])))
					)
				);
				$this->theme->build('board');
				return FALSE;
			}
		}

		// we reached the end with nothing
		return $this->show_404();
	}
	
	
	function action_search()
	{
		return $this->radix_search();
	}
	
	
	function radix_search()
	{
		if (\Input::get('submit_search_global'))
		{
			$this->_radix = null;
		}
		
		$text = \Input::get('text');
		
		if ($this->_radix !== null && (\Input::get('submit_post') || (\Input::get('submit_undefined')
				&& (\Board::is_valid_post_number($text) || strpos($text, '//boards.4chan.org') !== false))))
		{
			$this->post(str_replace(',', '_', $text));
		}
		
		// Check all allowed search modifiers and apply only these
		$modifiers = array(
			'subject', 'text', 'username', 'tripcode', 'email', 'filename', 'capcode',
			'image', 'deleted', 'ghost', 'type', 'filter', 'start', 'end',
			'order', 'page');
		
		if(\Auth::has_access('comment.see_ip'));
		{
			$modifiers[] = 'poster_ip';
			$modifiers[] = 'deletion_mode';
		}
		
		// GET -> URL Redirection to provide URL presentable for sharing links.
		if (!\Input::post('deletion_mode_captcha') && \Input::get())
		{
			if ($this->_radix !== null)
			{
				$redirect_url = array($this->_radix->shortname, 'search');
			}
			else
			{
				$redirect_url = array('_', 'search');
			}

			foreach ($modifiers as $modifier)
			{
				if (\Input::get($modifier))
				{
					array_push($redirect_url, $modifier);

					if($modifier == 'image')
					{
						array_push($redirect_url, 
							rawurlencode(static::urlsafe_b64encode(static::urlsafe_b64decode(\Input::get($modifier)))));
					}
					else
					{
						array_push($redirect_url, rawurlencode(\Input::get($modifier)));
					}
				}
			}

			\Response::redirect(\Uri::create($redirect_url), 'location', 303);
		}
		
		$search = \Uri::uri_to_assoc(\Uri::segments(), 2, $modifiers);

		// latest searches system
		if( ! is_array($cookie_array = @json_decode(\Cookie::get('search_latest_5'), true)))
		{
			$cookie_array = array();
		}
		
		// sanitize
		foreach($cookie_array as $item)
		{
			// all subitems must be array, all must have 'board'
			if( ! is_array($item) || ! isset($item['board']))
			{
				$cookie_array = array();
				break;
			}
		}
		
		$search_opts = array_filter($search);

		$search_opts['board'] = $this->_radix !== null ? $this->_radix->shortname : false;
		unset($search_opts['page']);
		
		// if it's already in the latest searches, remove the previous entry
		foreach($cookie_array as $key => $item)
		{
			if($item === $search_opts)
			{
				unset($cookie_array[$key]);
				break;
			}
		}
		
		// we don't want more than 5 entries for latest searches
		if(count($cookie_array) > 4)
		{
			array_pop($cookie_array);
		}
		
		array_unshift($cookie_array, $search_opts);
		\Cookie::set('search_latest_5', json_encode($cookie_array), 60 * 60 * 24 * 30);

		try
		{
			$board = \Search::forge()
				->get_search($search)
				->set_radix($this->_radix)
				->set_page(isset($search['page']) ? $search['page'] : 1);
			$board->get_comments();
		}
		catch (Model\SearchException $e)
		{
			return $this->error($e);
		}
		catch (Model\BoardException $e)
		{
			return $this->error($e);
		}
		
		// Generate the $title with all search modifiers enabled.
		$title = array();

		if ($search['text'])
			array_push($title,
				sprintf(__('that contain &lsquo;%s&rsquo;'),
					trim(e(urldecode($search['text'])))));
		if ($search['subject'])
			array_push($title,
				sprintf(__('with the subject &lsquo;%s&rsquo;'),
					trim(e(urldecode($search['subject'])))));
		if ($search['username'])
			array_push($title,
				sprintf(__('with the username &lsquo;%s&rsquo;'),
					trim(e(urldecode($search['username'])))));
		if ($search['tripcode'])
			array_push($title,
				sprintf(__('with the tripcode &lsquo;%s&rsquo;'),
					trim(e(urldecode($search['tripcode'])))));
		if ($search['filename'])
			array_push($title,
				sprintf(__('with the filename &lsquo;%s&rsquo;'),
					trim(e(urldecode($search['filename'])))));
		if ($search['image'])
		{
			// non-urlsafe else urlsafe
			if (mb_strlen(urldecode($search['image'])) > 22)
			{
				array_push($title,
					sprintf(__('with the image hash &lsquo;%s&rsquo;'),
						trim(rawurldecode($search['image']))));
			}
			else
			{
				$search['image'] = static::urlsafe_b64encode(static::urlsafe_b64decode($search['image']));
				array_push($title,
					sprintf(__('with the image hash &lsquo;%s&rsquo;'),
						trim($search['image'])));
			}
		}
		if ($search['deleted'] == 'deleted')
			array_push($title, __('that have been deleted'));
		if ($search['deleted'] == 'not-deleted')
			array_push($title, __('that has not been deleted'));
		if ($search['ghost'] == 'only')
			array_push($title, __('that are by ghosts'));
		if ($search['ghost'] == 'none')
			array_push($title, __('that are not by ghosts'));
		if ($search['type'] == 'op')
			array_push($title, __('that are only OP posts'));
		if ($search['type'] == 'posts')
			array_push($title, __('that are only non-OP posts'));
		if ($search['filter'] == 'image')
			array_push($title, __('that do not contain images'));
		if ($search['filter'] == 'text')
			array_push($title, __('that only contain images'));
		if ($search['capcode'] == 'user')
			array_push($title, __('that were made by users'));
		if ($search['capcode'] == 'mod')
			array_push($title, __('that were made by mods'));
		if ($search['capcode'] == 'admin')
			array_push($title, __('that were made by admins'));
		if ($search['start'])
			array_push($title, sprintf(__('posts after %s'), trim(e($search['start']))));
		if ($search['end'])
			array_push($title, sprintf(__('posts before %s'), trim(e($search['end']))));
		if ($search['order'] == 'asc')
			array_push($title, __('in ascending order'));
		if (!empty($title))
		{
			$title = sprintf(__('Searching for posts %s.'),
				implode(' ' . __('and') . ' ', $title));
		}
		else
		{
			$title = __('Displaying all posts with no filters applied.');
		}
		
		$this->_theme->set_title(\Radix::get_selected()->formatted_title.' &raquo; '.$title);
		$this->_theme->bind('board', $board);
		
		$pagination = $search;
		unset($pagination['page']);
		$this->_theme->bind('pagination', array(
				'base_url' => \Uri::create(array_merge(
					array($this->_radix !== null ?$this->_radix->shortname : '_', 'search'), $pagination)),
				'current_page' => $search['page'] ? : 1,
				'total' => $board->get_count()
			));
		$this->_theme->bind('search', $pagination);
		
		\Profiler::mark_memory($this, 'Controller Chan $this');
		\Profiler::mark('Controller Chan::search End');
		return \Response::forge($this->_theme->build('board'));
		
		
	}


	public function radix_submit()
	{
		// adapter
		if(!\Input::post())
		{
			return $this->error(__('You aren\'t sending the required fields for creating a new message.'));
		}

		// Determine if the invalid post fields are populated by bots.
		if (isset($post['name']) && mb_strlen($post['name']) > 0)
			return $this->error();
		if (isset($post['reply']) && mb_strlen($post['reply']) > 0)
			return $this->error();
		if (isset($post['email']) && mb_strlen($post['email']) > 0)
			return $this->error();

		$data = array();

		$post = \Input::post();

		if(isset($post['reply_numero']))
			$data['thread_num'] = $post['reply_numero'];
		if(isset($post['reply_bokunonome']))
			$data['name'] = $post['reply_bokunonome'];
		if(isset($post['reply_elitterae']))
			$data['email'] = $post['reply_elitterae'];
		if(isset($post['reply_talkingde']))
			$data['title'] = $post['reply_talkingde'];
		if(isset($post['reply_chennodiscursus']))
			$data['comment'] = $post['reply_chennodiscursus'];
		if(isset($post['reply_nymphassword']))
			$data['delpass'] = $post['reply_nymphassword'];
		if(isset($post['reply_nymphassword']))
			$data['delpass'] = $post['reply_nymphassword'];
		if(isset($post['reply_nymphblind']))
			$data['spoiler'] = $post['reply_nymphblind'];
		if(isset($post['reply_postas']))
			$data['capcode'] = $post['reply_postas'];

		$media = null;

		if (count(\Upload::get_files()))
		{
			try
			{
				$media = \Media::forge_from_upload($this->_radix);
			}
			catch (\Model\MediaUploadNoFileException $e)
			{
				$media = null;
			}
			catch (\Model\MediaUploadException $e)
			{
				return $this->error($e->getMessage());
			}
		}

		return $this->submit($data, $media);
	}

	public function submit($data, $media)
	{
		// some beginners' validation, while through validation will happen in the Comment model
		$val = \Validation::forge();
		$val->add_field('thread_num', __('Thread Number'), 'required');
		$val->add_field('name', __('Username'), 'trim|max_length[64]');
		$val->add_field('email', __('Email'), 'trim|max_length[64]');
		$val->add_field('title', __('Subject'), 'trim|max_length[64]');
		$val->add_field('comment', __('Comment'), 'trim|min_length[3]|max_length[4096]');
		$val->add_field('delpass', __('Password'), 'required|min_length[3]|max_length[32]');

		// leave the capcode check to the model

		if($val->run($data))
		{
			try
			{
				$comment = \Comment::forge((object) $data, $this->_radix, array('clean' => false));
				$comment->media = $media;
				$comment->insert();
			}
			catch (Model\CommentSendingException $e)
			{
				if (\Input::is_ajax())
				{
					return \Response::forge(json_encode(array('error' => $e->getMessage())));
				}
				else
				{
					return $this->error(implode(' ', $val->error()));
				}
			}
		}
		else
		{
			return $this->error(implode(' ', $val->error()));
		}

		if (\Input::is_ajax())
		{
			$comment_api = \Comment::forge_for_api($comment, $this->_radix, array('board' => false, 'formatted' => true));
			return \Response::forge(
				json_encode(array('success' => __('Message sent.'), $comment->thread_num => array('posts' => array($comment_api)))));
		}
		else
		{
			$this->_theme->set_layout('redirect');
			return \Response::forge($this->_theme->build('redirect',
				array('url' => \Uri::create($this->_radix->shortname.'/thread/'.$comment->thread_num.'/'.$comment->num))));
		}

	}
	
}