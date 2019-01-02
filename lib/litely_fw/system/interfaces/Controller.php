<?php
/**
 * Created by PhpStorm.
 * User: brially
 * Date: 12/24/18
 * Time: 10:01 AM
 */

namespace LitelyFw\System\Interfaces;


interface Controller
{

    public function index();

    public function create();

    public function store($data = []);

    public function show($id);

    public function edit($id);

    public function update($data);

    public function destroy($id);
}