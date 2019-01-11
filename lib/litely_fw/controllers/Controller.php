<?php
/**
 * Created by PhpStorm.
 * User: brially
 * Date: 12/24/18
 * Time: 9:57 AM
 */

namespace LitelyFw\Controllers;

use \LitelyFw\System\Interfaces\Controller as ControllerInterface;

class Controller implements ControllerInterface
{
    protected $is_api;

    protected $method;

    protected $post_input;

    protected $get_input;

    protected $id_field = 'id';

    protected $view;

    protected $view_404 = 'partial/404';





    public function __construct($is_api = false)
    {
        $this->is_api = $is_api;

        $this->method = $_SERVER['REQUEST_METHOD'];

        $this->get_input = $_GET;

        $this->post_input = $_POST;

        $this->view = new View();

    }

    public final function serve(){
        switch($this->method){
            case "GET":
                if(isset($this->get_input['edit']) && isset($this->get_input[$this->id_field]) ) return $this->edit($this->get_input[$this->id_field]);
                if( isset($this->get_input[$this->id_field]) ) return $this->show($this->get_input[$this->id_field]);
                if( isset($this->get_input['create']) ) return $this->create();
                return $this->index();
                break;
            case "POST":
                return $this->store($this->post_input);
                break;
            case "PUT":
                if(isset($this->post_input[$this->id_field])) return $this->update($this->post_input[$this->id_field], $this->post_input);
                break;
            case "DELETE":
                if(isset($this->post_input[$this->id_field])) return $this->destroy($this->post_input[$this->id_field]);
                break;

        }
        return $this->return_404();

    }

    public function index()
    {
        return $this->return_404();
    }
    public function create()
    {
        return $this->return_404();
    }
    public function store($data = [])
    {
        return $this->return_404();
    }
    public function show($id)
    {
        return $this->return_404();
    }
    public function edit($id)
    {
        return $this->return_404();
    }
    public function update($id, $data)
    {
        return $this->return_404();
    }
    public function destroy($id)
    {
        return $this->return_404();
    }

    protected function return_404(){
        if($this->is_api) $this->view->sendData(['error'=>404, "error_message"=>'page not found'], 404);
        else $this->view->show($this->view_404, [], 404);
        return die();
    }

}
