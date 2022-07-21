<?php

namespace Chat;

use Scheduler\IAsyncTaskParameters;

class AsyncTaskUserParams implements IAsyncTaskParameters
{
    public User $User;
}