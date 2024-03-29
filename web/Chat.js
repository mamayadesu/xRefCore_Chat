class Chat {
    constructor() {
        this.lp = null;
        
        this.list = [];
        this.typing = new Typing();
        this.username = getCookie("username");
        this.lostconnection = false;
        
        this.auth = document.getElementById("auth");
        this.chat = document.getElementById("chat");
        this.anothertab = document.getElementById("anothertab");
        
        window.onbeforeunload = function() {
            if (this.lp != null) {
                this.lp.halt();
            }
            var http = new XMLHttpRequest();
            http.open("GET", "logout", true);
            http.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            http.send("");
            leave();
        }.bind(this);
    }
    
    checkAuth() {
        this.auth.style.display = "none";
        this.chat.style.display = "none";
        
        var http = new XMLHttpRequest();
        http.open('GET', 'checkauth', true);

        http.onreadystatechange = function() {
            if (http.readyState == 4 && http.status == 200) {
                if(http.responseText == "YES") {
                    this.chat.style.display = "";
                    this.auth.style.display = "none";
                    this.start();
                } else if(http.responseText == "NO") {
                    this.auth.style.display = "";
                    this.chat.style.display = "none";
                } else if(http.responseText == "ANOTHERTAB") {
                    this.chat.style.display = "none";
                    this.auth.style.display = "none";
                    this.anothertab.style.display = "";
                }
            }
        }.bind(this);
        http.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        http.send("");
    }

    handleDataRow(data, firstTime) {
        if (data["type"] == "message") {
            if (!firstTime) {
                this.typing.unsetTypingUser(data["sender"]);
            }
            document.getElementById("messages").innerHTML = "<pre><u>"+data["date"]+"</u> <b>"+data['sender']+":</b> "+data['message']+"</pre>"+document.getElementById("messages").innerHTML;
        } else if(data["type"] == "system") {
            document.getElementById("messages").innerHTML = "<pre><u>"+data["date"]+"</u> <i>"+data['message']+"</i></pre>"+document.getElementById("messages").innerHTML;
        } else if(data["type"] == "connected") {
            document.getElementById("messages").innerHTML = "<pre><u>"+data["date"]+"</u> <i>User "+data["username"]+" joined to chat.</i></pre>"+document.getElementById("messages").innerHTML;
        } else if(data["type"] == "disconnected") {
            if (!firstTime) {
                this.typing.unsetTypingUser(data["username"]);
            }
            document.getElementById("messages").innerHTML = "<pre><u>"+data["date"]+"</u> <i>User "+data["username"]+" left (disconnected).</i></pre>"+document.getElementById("messages").innerHTML;
        } else if(data["type"] == "timed out") {
            document.getElementById("messages").innerHTML = "<pre><u>"+data["date"]+"</u> <i>User "+data["username"]+" left ("+data["username"]+" timed out).</i></pre>"+document.getElementById("messages").innerHTML;
        } else if(data["type"] == "kicked") {
            if (!firstTime) {
                this.typing.unsetTypingUser(data["username"]);
                if (data['username'] == this.username) {
                    this.lp.halt();
                    var kicktext = "You were kicked by next reason: "+data["reason"];
                    switch (data["reason"]) {
                        case "Unauthorized":
                            kicktext = "You have been automatically disconnected from the chat. This may have occurred due to the fact that your computer or phone did not respond to server requests for a long time. Enter your username and enter the chat again.";
                            break;
                    }
                    window.alert(kicktext);
                    this.chat.style.display = "none";
                    this.auth.style.display = "block";
                }
            }
            document.getElementById("messages").innerHTML = "<pre><u>"+data["date"]+"</u> <i>User "+data["username"]+" left ("+data['reason']+").</i></pre>"+document.getElementById("messages").innerHTML;
        } else if(data["type"] == "typing" && !firstTime) {
            this.typing.setTypingUser(data["username"]);
        } else if(data["type"] == "list") {
            this.list = data["list"];
            this.handleUsersList();
        }
    }
    
    start() {
        document.getElementById("lc").style.display = "none";

        this.loadMessages();
        this.lp = new LongPoll("/load");

        this.lp.on("data", function(data) {
            if (data["type"] != "kicked" && this.lostconnection) {
                this.lostconnection = false;
                document.getElementById("lc").style.display = "none";
                this.loadMessages();
                window.alert("Connection restored!");
            }
            this.handleDataRow(data, false)
            console.log("[Chat]", "New data received", data);
        }.bind(this));
        
        this.lp.on("halted", function() {
            console.log("[Chat]", "Halt");
        }.bind(this));
            
        this.lp.on("error", function(data) {
            if (!this.lostconnection) {
                document.getElementById("lc").style.display = "";
                window.alert("Connection lost.");
                console.log.apply(console, ["[Chat]", "Connection lost", data]);
            }
            this.lostconnection = true;
        }.bind(this));
            
        this.lp.on("timeout", function() {
            console.log.apply(console, ["[Chat]", "Connection timeout"]);
        }.bind(this));
            
        this.lp.on("hibernation", function() {
            if(this.lostconnection) {
                this.lostconnection = false;
                document.getElementById("lc").style.display = "none";
                this.loadMessages();
                window.alert("Connection restored!");
            }
        }.bind(this));
    }
    
    handleUsersList() {
        var el = document.getElementById("userslist");
        el.innerHTML = "<tr><th>Users connected</th></tr>";
        
        for (var k in this.list)
        {
            el.innerHTML += "<tr><td>" + this.list[k] + "</td></tr>";
        }
    }
    
    loadMessages() {
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "/load", true);
        xhr.timeout = 10000;
        document.getElementById("messages").innerHTML = "";

        xhr.ontimeout = function() {
            window.alert("Failed to load messages. Connection timeout");
        };

        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4 && xhr.status == 200) {
                var response = xhr.responseText;
                var string_response = response + "";
                var responses = string_response.split("\n");
                var count = 0;
                if (string_response.length > 0) {
                    for (var key in responses) {
                        var r = responses[key];
                        var data;
                        if (r.length > 0) {
                            if (typeof r != "object") {
                                try {
                                    data = JSON.parse(r);
                                } catch(e) {
                                    r = this.lp.b64decode(r);
                                    data = JSON.parse(r);
                                }
                            }
                            count++;
                            this.handleDataRow(data, true);
                        }
                    }
                    console.log(count + " messages loaded.");
                }
            }
        }.bind(this);
        var parameters = { "firstload": "yes" };
        var parameters_string;
        var count = 0;
        for (var key in parameters) {
            var value = parameters[key];
            count++;
            if (count == 1) {
                parameters_string = key + "=" + encodeURIComponent(value);
            } else {
                parameters_string = result + "&" + key + "=" + encodeURIComponent(value);
            }
        }
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.send(parameters_string);
    }
    
    join() {
        document.getElementById("join_button").disabled = true;
        document.getElementById("join_button").value = "Connecting...";

        var authresult_block = document.getElementById("authresult");
        authresult_block.innerHTML = "<span style='color: white;'>_</span>";

        var http = new XMLHttpRequest();
        http.open("POST", "join", true);
        http.timeout = 20000;

        http.ontimeout = function() {
            document.getElementById('join_button').disabled = false;
            document.getElementById('join_button').value = "Join";
            this.chat.style.display = "none";
            this.auth.style.display = "block";
            authresult.innerHTML = "Connection timeout.";
        }.bind(this);

        http.onreadystatechange = function() {
            if (http.readyState == 4) {
                if (http.status == 200) {
                    document.getElementById("join_button").disabled = false;
                    document.getElementById("join_button").value = "Join";
                    switch(http.responseText) {
                        case "OK":
                            this.auth.style.display = "none";
                            this.chat.style.display = "";
                            this.username = document.getElementById("sender").value;
                            document.getElementById("messages").innerHTML = "";
                            document.getElementById("result").innerHTML = "<span style='color: white;'>_</span>";
                            this.start();
                            break;
                            
                        case "TOOSHORT":
                            this.chat.style.display = "none";
                            this.auth.style.display = "";
                            authresult.innerHTML = "Username is too short";
                            break;
                            
                        case "TOOLONG":
                            this.chat.style.display = "none";
                            this.auth.style.display = "";
                            authresult.innerHTML = "Username is too long";
                            break;
                            
                        case "ALREADYUSING":
                            this.chat.style.display = "none";
                            this.auth.style.display = "";
                            authresult.innerHTML = "Username is already using";
                            break;
                            
                        case "WRITEYOURUSERNAME":
                            this.chat.style.display = "none";
                            this.auth.style.display = "";
                            authresult.innerHTML = "Input username";
                            break;

                        case "CHATISFULL":
                            this.chat.style.display = "none";
                            this.auth.style.display = "";
                            authresult.innerHTML = "User's count limit is reached. Please wait when someone left.";
                            break;

                        case "DISABLED":
                            this.chat.style.display = "none";
                            this.auth.style.display = "";
                            authresult.innerHTML = "Chat disabled.";
                            break;
                            
                        case "TOOMANYUSERWITHTHISIP":
                            this.chat.style.display = "none";
                            this.auth.style.display = "";
                            authresult.innerHTML = "Too many users with the same IP";
                            break;
                    }
                } else {
                    document.getElementById('join_button').disabled = false;
                    document.getElementById('join_button').value = "Join";
                    authresult_block.innerHTML = "Failed to authorize";
                }
            }
        }.bind(this);
        http.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        http.send("username=" + encodeURIComponent(document.getElementById("sender").value));
    }
    
    send() {
        document.getElementById('send_message_button').disabled = true;
        var sender = document.getElementById('sender').value;
        var message = document.getElementById('message').value;
        var unixtime = new Date;
        var month = (unixtime.getMonth() + 1) + "";
        var day = (unixtime.getDate()) + "";
        var year = unixtime.getFullYear();
        var hour = (unixtime.getHours()) + "";
        var minute = (unixtime.getMinutes()) + "";
        var second = (unixtime.getSeconds()) + "";

        if(hour.length == 1) {
            hour = "0" + hour;
        }
        if(minute.length == 1) {
            minute = "0" + minute;
        }
        if(second.length == 1) {
            second = "0" + second;
        }
        if(day.length == 1) {
            day = "0" + day;
        }
        if(month.length == 1) {
            month = "0" + month;
        }
        
        var timenow = "<u>" + day + "." + month + "." + year + " " + hour + ":" + minute + ":" + second + "</u>";
        
        var result_block = document.getElementById("result");
        
        document.getElementById("sending_message").innerHTML = "<pre style='color: grey;'>" + timenow+" <b>" + sender + ": "+message+"</b></pre>";
        
        result_block.innerHTML = "<span style='color: white;'>_</span>";
        
        var http = new XMLHttpRequest();
        http.open("POST", "send", true);
        http.timeout = 20000;
        http.ontimeout = function() {
            document.getElementById("send_message_button").disabled = false;
            result_block.innerHTML = "Connection timeout.";
            document.getElementById("sending_message").innerHTML = "";
        }
        http.onreadystatechange = function() {
            if(http.readyState == 4) {
                document.getElementById("sending_message").innerHTML = '';
                document.getElementById("send_message_button").disabled = false;
                if(http.status == 200) {
                    if(http.responseText == "OK") {
                        //result_block.innerHTML = "Сообщение отправлено!";
                        result_block.innerHTML = "<span style='color: white;'>_</span>";
                        document.getElementById("message").value = "";
                    } else if(http.responseText == "EMPTY") {
                        result_block.innerHTML = "Fill all fields!";
                    } else if(http.responseText == "NOTAUTHORIZED") {
                        result_block.innerHTML = "You are not authorized. This error may have occurred because the connection to the chat was lost. Refresh the page and enter your username again.";
                    }
                } else {
                    result_block.innerHTML = "Failed to send message";
                }
            }
        }
        http.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        http.send("sender=" + encodeURIComponent(sender) + "&message=" + encodeURIComponent(message));
    }
    
    leave() {
        if (confirm("Leave chat?")) {
            this.lp.halt();
            var http = new XMLHttpRequest();
            http.open('GET', "logout", true);
            http.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            http.send("");
            this.chat.style.display = "none";
            this.auth.style.display = "block";
        }
    }
}